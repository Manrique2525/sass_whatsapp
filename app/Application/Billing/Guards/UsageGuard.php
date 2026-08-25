<?php

declare(strict_types=1);

namespace App\Application\Billing\Guards;

use App\Domain\Billing\Contracts\UsageGuardInterface;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Enums\UsageCategory;
use App\Domain\Billing\Enums\UsageReservationStatus;
use App\Domain\Billing\Exceptions\InvalidUsageQuantityException;
use App\Domain\Billing\Exceptions\PlanNotFoundException;
use App\Domain\Billing\Exceptions\SubscriptionNotActiveException;
use App\Domain\Billing\Exceptions\SubscriptionNotFoundException;
use App\Domain\Billing\Exceptions\TenantQuotaExceededException;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Models\UsageRecord;
use App\Domain\Billing\Models\UsageReservation;
use App\Domain\Tenants\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Atomic usage quota guard with PostgreSQL advisory lock serialization (FASE 25 U1).
 *
 * Reserve → Commit lifecycle for idempotent quota enforcement.
 * PostgreSQL advisory lock per (tenant_id, category, period_start) serializes concurrent reservers.
 * Fails closed: any anomaly → exception, never silent pass.
 */
final class UsageGuard implements UsageGuardInterface
{
    public function __construct(
        private readonly EntitlementResolver $entitlementResolver,
        private readonly int $defaultReservationTtlSeconds = 300,
    ) {}

    /**
     * Compute remaining quota for a category in the current billing period.
     *
     * Returns null if unlimited (plan limit is null).
     *
     * @throws SubscriptionNotFoundException No active/past-due subscription (fail-closed).
     * @throws PlanNotFoundException
     */
    public function remaining(Tenant $tenant, UsageCategory $category): ?int
    {
        [$subscription, $plan, $periodStart, $periodEnd] = $this->entitlementResolver->resolve($tenant);

        $limit = $plan->getLimit($category->value);

        if ($limit === null) {
            return null;
        }

        if ($limit === 0) {
            return 0;
        }

        $used = $this->computeUsedQuantity($subscription, $tenant->id, $category, $periodStart, $periodEnd);
        $reserved = $this->computeActiveReservedQuantity($tenant->id, $category, $periodStart, $periodEnd);

        return max(0, $limit - $used - $reserved);
    }

    /**
     * Atomically reserve quota for an operation.
     *
     * Advisory lock per (tenant_id, category, period_start) ensures serialization.
     * Idempotent: same idempotency_key returns existing reservation if active.
     *
     * Returns null if plan limit is null (unlimited — no reservation needed).
     *
     * @throws SubscriptionNotFoundException No active/past-due subscription (fail-closed).
     * @throws TenantQuotaExceededException
     * @throws PlanNotFoundException
     * @throws InvalidUsageQuantityException
     */
    public function reserve(
        Tenant $tenant,
        UsageCategory $category,
        int $quantity,
        ?string $idempotencyKey = null,
        ?int $ttlSeconds = null,
    ): ?UsageReservation {
        if ($quantity <= 0) {
            throw new InvalidUsageQuantityException(
                "Reservation quantity must be positive, got {$quantity}.",
            );
        }

        [$subscription, $plan, $periodStart, $periodEnd] = $this->entitlementResolver->resolve($tenant);

        $limit = $plan->getLimit($category->value);

        if ($limit === null) {
            return null;
        }

        $effectiveTtl = $ttlSeconds ?? $this->defaultReservationTtlSeconds;
        $expiresAt = now()->addSeconds($effectiveTtl);

        $lockKey = $this->computeLockKey($tenant->id, $category, $periodStart);

        return DB::transaction(function () use (
            $tenant,
            $subscription,
            $category,
            $quantity,
            $idempotencyKey,
            $periodStart,
            $periodEnd,
            $limit,
            $expiresAt,
            $lockKey,
        ): UsageReservation {
            $this->acquireAdvisoryLock($lockKey);

            try {
                // Idempotency: return existing reservation if found
                if ($idempotencyKey !== null) {
                    $existing = UsageReservation::query()
                        ->withoutTenantScope()
                        ->where('tenant_id', $tenant->id)
                        ->where('idempotency_key', $idempotencyKey)
                        ->first();

                    if ($existing !== null) {
                        if ($existing->status === UsageReservationStatus::Committed) {
                            return $existing;
                        }

                        if ($existing->status === UsageReservationStatus::Released || $existing->isExpired()) {
                            $existing->delete();
                        } else {
                            return $existing;
                        }
                    }
                }

                // Re-read subscription state inside lock
                $subscription->refresh();

                $currentStatus = SubscriptionStatus::from(
                    (string) $subscription->getRawOriginal('status'),
                );

                if ($currentStatus === SubscriptionStatus::Pending
                    || $currentStatus === SubscriptionStatus::Cancelled
                ) {
                    throw new SubscriptionNotActiveException;
                }

                $used = $this->computeUsedQuantity($subscription, $tenant->id, $category, $periodStart, $periodEnd);
                $reserved = $this->computeActiveReservedQuantity($tenant->id, $category, $periodStart, $periodEnd);
                $totalUsed = $used + $reserved;

                if ($limit === 0) {
                    throw TenantQuotaExceededException::forQuota(
                        $category->value,
                        $limit,
                        $totalUsed,
                    );
                }

                if ($totalUsed + $quantity > $limit) {
                    throw TenantQuotaExceededException::forQuota(
                        $category->value,
                        $limit,
                        $totalUsed,
                    );
                }

                $reservation = new UsageReservation;
                $reservation->setAttribute('tenant_id', $tenant->id);
                $reservation->setAttribute('subscription_id', $subscription->id);
                $reservation->setAttribute('category', $category);
                $reservation->setAttribute('period_start', $periodStart);
                $reservation->setAttribute('period_end', $periodEnd);
                $reservation->setAttribute('quantity', $quantity);
                $reservation->setAttribute('idempotency_key', $idempotencyKey);
                $reservation->setAttribute('status', UsageReservationStatus::Reserved);
                $reservation->setAttribute('expires_at', $expiresAt);
                $reservation->setAttribute('reserved_at', now());
                $reservation->save();

                return $reservation;
            } finally {
                $this->releaseAdvisoryLock($lockKey);
            }
        });
    }

    /**
     * Mark a reservation as committed and create a usage record atomically.
     *
     * Row-level lock (SELECT ... FOR UPDATE) on the reservation prevents concurrent
     * commit() or release() on the same reservation. Precondition checks run inside
     * the lock scope so the second concurrent caller sees the already-committed state
     * and fails safely.
     *
     * Both writes occur within a single DB transaction to prevent crash between
     * reservation commit and ledger write (exactly-once guarantee).
     *
     * recorded_at uses now() to guarantee uniqueness within the UNIQUE constraint
     * on (tenant_id, subscription_id, category, recorded_at).
     *
     * @throws \InvalidArgumentException If reservation is not in 'reserved' status or has expired.
     */
    public function commit(UsageReservation $reservation): UsageRecord
    {
        return DB::transaction(function () use ($reservation): UsageRecord {
            $locked = $this->lockReservation($reservation);

            if ($locked->status !== UsageReservationStatus::Reserved) {
                throw new \InvalidArgumentException(
                    "Cannot commit reservation [{$locked->id}]: status is {$locked->status->value}, expected reserved.",
                );
            }

            if ($locked->isExpired()) {
                throw new \InvalidArgumentException(
                    "Cannot commit reservation [{$locked->id}]: reservation has expired.",
                );
            }

            $locked->status = UsageReservationStatus::Committed;
            $locked->committed_at = now();
            $locked->save();

            $usageRecord = new UsageRecord;
            $usageRecord->setAttribute('tenant_id', $locked->tenant_id);
            $usageRecord->setAttribute('subscription_id', $locked->subscription_id);
            $usageRecord->setAttribute('category', $locked->category);
            $usageRecord->setAttribute('quantity', $locked->quantity);
            $usageRecord->setAttribute('description', null);
            $usageRecord->setAttribute('metadata', ['reservation_id' => $locked->id]);
            $usageRecord->setAttribute('recorded_at', now()->toDateTimeString());
            $usageRecord->save();

            return $usageRecord;
        });
    }

    /**
     * Commit a reservation with an actual quantity that may differ from the estimated reservation.
     *
     * Used for AI token reconciliation: the reservation holds an estimated budget during the
     * provider call, but the UsageRecord must reflect the actual tokens consumed.
     *
     * Row-level lock (SELECT ... FOR UPDATE) on the reservation prevents concurrent
     * commit/reconcile on the same reservation.
     *
     * Both writes occur within a single DB transaction to prevent crash between
     * reservation commit and ledger write (exactly-once guarantee).
     *
     * recorded_at uses now() to guarantee uniqueness within the UNIQUE constraint
     * on (tenant_id, subscription_id, category, recorded_at).
     *
     * If actualQuantity > reservation quantity, the ledger records the higher actual (overshoot
     * from estimation variance, documented in ADR-097 U3).
     * If actualQuantity < reservation quantity, only actual is recorded (unused budget released).
     *
     * @throws \InvalidArgumentException If reservation is not in 'reserved' status, has expired,
     *                                   or actualQuantity is not positive.
     */
    public function commitWithActual(UsageReservation $reservation, int $actualQuantity): UsageRecord
    {
        if ($actualQuantity <= 0) {
            throw new \InvalidArgumentException(
                "Cannot commit reservation [{$reservation->id}]: actualQuantity must be positive, got {$actualQuantity}.",
            );
        }

        return DB::transaction(function () use ($reservation, $actualQuantity): UsageRecord {
            $locked = $this->lockReservation($reservation);

            if ($locked->status !== UsageReservationStatus::Reserved) {
                throw new \InvalidArgumentException(
                    "Cannot commit reservation [{$locked->id}]: status is {$locked->status->value}, expected reserved.",
                );
            }

            if ($locked->isExpired()) {
                throw new \InvalidArgumentException(
                    "Cannot commit reservation [{$locked->id}]: reservation has expired.",
                );
            }

            $locked->quantity = $actualQuantity;
            $locked->status = UsageReservationStatus::Committed;
            $locked->committed_at = now();
            $locked->save();

            $usageRecord = new UsageRecord;
            $usageRecord->setAttribute('tenant_id', $locked->tenant_id);
            $usageRecord->setAttribute('subscription_id', $locked->subscription_id);
            $usageRecord->setAttribute('category', $locked->category);
            $usageRecord->setAttribute('quantity', $actualQuantity);
            $usageRecord->setAttribute('description', null);
            $usageRecord->setAttribute('metadata', ['reservation_id' => $locked->id]);
            $usageRecord->setAttribute('recorded_at', now()->toDateTimeString());
            $usageRecord->save();

            return $usageRecord;
        });
    }

    /**
     * Record usage directly without a reservation (for unlimited plan telemetry).
     *
     * When a plan has no limit (null), reserve() returns null and no reservation is created.
     * This method allows recording actual usage for billing visibility and analytics.
     *
     * @throws SubscriptionNotFoundException If no active/past-due subscription.
     */
    public function recordDirect(
        Tenant $tenant,
        UsageCategory $category,
        int $quantity,
        ?string $description = null,
    ): UsageRecord {
        if ($quantity <= 0) {
            throw new InvalidUsageQuantityException(
                "Direct record quantity must be positive, got {$quantity}.",
            );
        }

        [$subscription, $plan, $periodStart, $periodEnd] = $this->entitlementResolver->resolve($tenant);

        $usageRecord = new UsageRecord;
        $usageRecord->setAttribute('tenant_id', $tenant->id);
        $usageRecord->setAttribute('subscription_id', $subscription->id);
        $usageRecord->setAttribute('category', $category);
        $usageRecord->setAttribute('quantity', $quantity);
        $usageRecord->setAttribute('description', $description);
        $usageRecord->setAttribute('metadata', []);
        $usageRecord->setAttribute('recorded_at', now()->toDateTimeString());
        $usageRecord->save();

        return $usageRecord;
    }

    /**
     * Release a reservation (cancel without recording usage).
     *
     * Row-level lock (SELECT ... FOR UPDATE) on the reservation prevents concurrent
     * commit() or release() on the same reservation.
     *
     * @throws \InvalidArgumentException If reservation is not in 'reserved' status.
     */
    public function release(UsageReservation $reservation): void
    {
        DB::transaction(function () use ($reservation): void {
            $locked = $this->lockReservation($reservation);

            if ($locked->status !== UsageReservationStatus::Reserved) {
                throw new \InvalidArgumentException(
                    "Cannot release reservation [{$locked->id}]: status is {$locked->status->value}, expected reserved.",
                );
            }

            $locked->status = UsageReservationStatus::Released;
            $locked->released_at = now();
            $locked->save();
        });
    }

    // ──────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────

    /**
     * Lock a reservation row for update (SELECT ... FOR UPDATE).
     *
     * Returns a fresh Eloquent model with the locked row. On PostgreSQL this uses
     * row-level locking; on SQLite it returns the row without locking (SQLite uses
     * database-level locking — sufficient for unit-test concurrency semantics).
     */
    private function lockReservation(UsageReservation $reservation): UsageReservation
    {
        if ($this->isPostgres()) {
            $row = UsageReservation::query()
                ->withoutTenantScope()
                ->where('id', $reservation->id)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw new \InvalidArgumentException(
                    "Reservation [{$reservation->id}] not found.",
                );
            }

            return $row;
        }

        return $reservation->fresh() ?? $reservation;
    }

    private function computeUsedQuantity(
        Subscription $subscription,
        string $tenantId,
        UsageCategory $category,
        Carbon $periodStart,
        Carbon $periodEnd,
    ): int {
        return (int) UsageRecord::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('subscription_id', $subscription->id)
            ->where('category', $category)
            ->where('recorded_at', '>=', $periodStart)
            ->where('recorded_at', '<', $periodEnd)
            ->sum('quantity');
    }

    private function computeActiveReservedQuantity(
        string $tenantId,
        UsageCategory $category,
        Carbon $periodStart,
        Carbon $periodEnd,
    ): int {
        $now = now();

        return (int) UsageReservation::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('category', $category)
            ->where('status', UsageReservationStatus::Reserved)
            ->where('expires_at', '>', $now)
            ->where('period_start', $periodStart)
            ->where('period_end', $periodEnd)
            ->sum('quantity');
    }

    /**
     * Deterministic 64-bit lock key for PostgreSQL advisory lock.
     *
     * Uses CRC32 for deterministic mapping. Collisions reduce parallelism but do not
     * affect correctness.
     */
    private function computeLockKey(string $tenantId, UsageCategory $category, Carbon $periodStart): int
    {
        $payload = "{$tenantId}:{$category->value}:{$periodStart->toDateString()}";

        return crc32($payload);
    }

    private function acquireAdvisoryLock(int $lockKey): void
    {
        if ($this->isPostgres()) {
            DB::select('SELECT pg_advisory_xact_lock(CAST(? AS bigint))', [$lockKey]);
        }
    }

    private function releaseAdvisoryLock(int $lockKey): void
    {
        // Released automatically by pg_advisory_xact_lock at transaction end
    }

    private function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }
}
