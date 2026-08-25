<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Domain\Billing\Contracts\UsageGuardInterface;
use App\Domain\Billing\Enums\UsageCategory;
use App\Domain\Billing\Models\UsageRecord;
use App\Domain\Billing\Models\UsageReservation;
use App\Domain\Tenants\Models\Tenant;

/**
 * Fake UsageGuardInterface for unit tests (FASE 25 U1).
 *
 * Defaults to unlimited plan (reserve returns null) so AI node tests
 * don't need billing infrastructure. Configurable via withReservation().
 */
final class FakeUsageGuard implements UsageGuardInterface
{
    private ?UsageReservation $reservation = null;

    public function reserve(
        Tenant $tenant,
        UsageCategory $category,
        int $quantity,
        ?string $idempotencyKey = null,
        ?int $ttlSeconds = null,
    ): ?UsageReservation {
        return $this->reservation;
    }

    public function commit(UsageReservation $reservation): UsageRecord
    {
        return new UsageRecord;
    }

    public function commitWithActual(UsageReservation $reservation, int $actualQuantity): UsageRecord
    {
        return new UsageRecord;
    }

    public function recordDirect(
        Tenant $tenant,
        UsageCategory $category,
        int $quantity,
        ?string $description = null,
    ): UsageRecord {
        return new UsageRecord;
    }

    public function release(UsageReservation $reservation): void
    {
        // no-op
    }

    public function remaining(Tenant $tenant, UsageCategory $category): ?int
    {
        return null;
    }

    public function withReservation(UsageReservation $reservation): self
    {
        $this->reservation = $reservation;

        return $this;
    }

    public function reset(): void
    {
        $this->reservation = null;
    }
}
