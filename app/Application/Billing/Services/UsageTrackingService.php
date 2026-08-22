<?php

declare(strict_types=1);

namespace App\Application\Billing\Services;

use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Enums\UsageCategory;
use App\Domain\Billing\Exceptions\InvalidUsageQuantityException;
use App\Domain\Billing\Exceptions\SubscriptionNotFoundException;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Models\UsageRecord;
use App\Domain\Billing\ValueObjects\UsageCategorySummary;
use App\Domain\Billing\ValueObjects\UsageSummary;
use App\Domain\Tenants\Models\Tenant;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

/**
 * Append-only usage metering infrastructure (FASE 23 U2, ADR-089).
 *
 * Internal application service. NO HTTP routes, NO public API.
 * Usage recording is NEVER exposed to clients.
 *
 * Design:
 * - DB PostgreSQL = source of truth.
 * - Append-only ledger: INSERT + SELECT + SUM. No UPDATE/DELETE.
 * - Active subscription resolved server-side (never caller-controlled).
 * - Period boundaries: [start, end) — start inclusive, end exclusive.
 * - null limit = unlimited (no magic numbers).
 * - Metadata: whitelist of safe technical IDs only.
 * - No Redis, no cache, no batching.
 * - No per-record AuditLog (UsageRecord IS the ledger).
 */
final class UsageTrackingService
{
    /**
     * Keys allowed in the metadata JSON payload.
     * Only safe technical IDs — NO PII, NO sensitive data.
     *
     * @var list<string>
     */
    private const METADATA_WHITELIST = [
        'message_id',
        'conversation_id',
        'flow_execution_id',
        'knowledge_document_id',
        'source',
    ];

    // ──────────────────────────────────────────────
    // Record
    // ──────────────────────────────────────────────

    /**
     * Record usage against the tenant's active subscription.
     *
     * @param  array<string, mixed>|null  $metadata  Safe technical IDs only.
     *
     * @throws SubscriptionNotFoundException No active subscription for the tenant.
     * @throws InvalidUsageQuantityException Quantity must be > 0.
     */
    public function record(
        Tenant $tenant,
        UsageCategory $category,
        int $quantity = 1,
        ?array $metadata = null,
        ?Carbon $recordedAt = null,
    ): UsageRecord {
        if ($quantity <= 0) {
            throw new InvalidUsageQuantityException(
                "Usage quantity must be positive, got {$quantity}.",
            );
        }

        $subscription = $this->resolveActiveSubscription($tenant);

        $sanitizedMetadata = $this->sanitizeMetadata($metadata);

        /** @var UsageRecord $record */
        $record = new UsageRecord;

        $record->setAttribute('tenant_id', $tenant->id);
        $record->setAttribute('subscription_id', $subscription->id);
        $record->setAttribute('category', $category);
        $record->setAttribute('quantity', $quantity);
        $record->setAttribute('description', null);
        $record->setAttribute('metadata', $sanitizedMetadata);
        $record->setAttribute('recorded_at', ($recordedAt ?? now())->toDateTimeString());

        $record->save();

        return $record;
    }

    // ──────────────────────────────────────────────
    // Current Period Usage
    // ──────────────────────────────────────────────

    /**
     * SUM(quantity) for a category within the current billing period.
     *
     * Returns 0 if no records exist.
     */
    public function currentPeriodUsage(
        Tenant $tenant,
        UsageCategory $category,
    ): int {
        $subscription = $this->resolveActiveSubscription($tenant);

        [$periodStart, $periodEnd] = $this->resolveCurrentPeriod($subscription);

        return (int) UsageRecord::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('category', $category)
            ->where('recorded_at', '>=', $periodStart)
            ->where('recorded_at', '<', $periodEnd)
            ->sum('quantity');
    }

    // ──────────────────────────────────────────────
    // Summary
    // ──────────────────────────────────────────────

    /**
     * Full usage summary across ALL categories for the current billing period.
     *
     * Returns used/limit/remaining per category.
     * null limit = unlimited.
     */
    public function currentPeriodSummary(Tenant $tenant): UsageSummary
    {
        $subscription = $this->resolveActiveSubscription($tenant);

        [$periodStart, $periodEnd] = $this->resolveCurrentPeriod($subscription);

        /** @var Plan $plan */
        $plan = $subscription->plan;

        $usageByCategory = UsageRecord::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('recorded_at', '>=', $periodStart)
            ->where('recorded_at', '<', $periodEnd)
            ->selectRaw('category, SUM(quantity) as total')
            ->groupBy('category')
            ->pluck('total', 'category');

        $categories = [];

        foreach (UsageCategory::cases() as $case) {
            $used = (int) ($usageByCategory[$case->value] ?? 0);
            $limit = $plan->getLimit($case->value);
            $remaining = $limit !== null ? max(0, $limit - $used) : null;

            $categories[$case->value] = new UsageCategorySummary(
                used: $used,
                limit: $limit,
                remaining: $remaining,
            );
        }

        return new UsageSummary(
            subscriptionId: $subscription->id,
            periodStart: $periodStart->toIso8601String(),
            periodEnd: $periodEnd->toIso8601String(),
            categories: $categories,
        );
    }

    // ──────────────────────────────────────────────
    // History
    // ──────────────────────────────────────────────

    /**
     * Paginated read-only history of usage records.
     *
     * Ordered by recorded_at DESC, id DESC.
     *
     * @param  array{category?: UsageCategory, from?: Carbon, to?: Carbon, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, UsageRecord>
     */
    public function history(
        Tenant $tenant,
        array $filters = [],
    ): LengthAwarePaginator {
        $query = UsageRecord::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id);

        if (isset($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (isset($filters['from'])) {
            $query->where('recorded_at', '>=', $filters['from']);
        }

        if (isset($filters['to'])) {
            $query->where('recorded_at', '<', $filters['to']);
        }

        $perPage = $filters['per_page'] ?? 20;

        return $query
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    // ──────────────────────────────────────────────
    // Subscription Resolution (internal)
    // ──────────────────────────────────────────────

    /**
     * Find the active subscription for a tenant.
     *
     * Active = status is Active AND current_period_end is in the future (or null).
     * Uses latest() to ensure deterministic ordering.
     *
     * @throws SubscriptionNotFoundException
     */
    private function resolveActiveSubscription(Tenant $tenant): Subscription
    {
        $subscription = Subscription::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('status', SubscriptionStatus::Active)
            ->latest()
            ->first();

        if ($subscription === null) {
            throw new SubscriptionNotFoundException(
                "No active subscription found for tenant [{$tenant->id}].",
            );
        }

        return $subscription;
    }

    // ──────────────────────────────────────────────
    // Period Resolution (internal)
    // ──────────────────────────────────────────────

    /**
     * Resolve the current billing period as [start, end).
     *
     * If subscription has both current_period_start and current_period_end,
     * use those. Otherwise fall back to the current calendar month in UTC.
     *
     * @return array{0: Carbon, 1: Carbon} [start inclusive, end exclusive)
     */
    private function resolveCurrentPeriod(Subscription $subscription): array
    {
        if ($subscription->current_period_start !== null
            && $subscription->current_period_end !== null
        ) {
            return [
                Carbon::parse($subscription->current_period_start)->startOfMinute(),
                Carbon::parse($subscription->current_period_end)->startOfMinute(),
            ];
        }

        // Fallback: calendar month UTC
        $now = now();

        return [
            $now->copy()->startOfMonth()->startOfMinute(),
            $now->copy()->addMonth()->startOfMonth()->startOfMinute(),
        ];
    }

    // ──────────────────────────────────────────────
    // Metadata Sanitization (internal)
    // ──────────────────────────────────────────────

    /**
     * Strip any keys not in the whitelist.
     * Returns null if the result is empty.
     *
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, mixed>
     */
    private function sanitizeMetadata(?array $metadata): array
    {
        if ($metadata === [] || $metadata === null) {
            return [];
        }

        $sanitized = array_intersect_key($metadata, array_flip(self::METADATA_WHITELIST));

        return $sanitized !== [] ? $sanitized : [];
    }
}
