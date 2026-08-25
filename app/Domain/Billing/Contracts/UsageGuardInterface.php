<?php

declare(strict_types=1);

namespace App\Domain\Billing\Contracts;

use App\Domain\Billing\Enums\UsageCategory;
use App\Domain\Billing\Models\UsageRecord;
use App\Domain\Billing\Models\UsageReservation;
use App\Domain\Tenants\Models\Tenant;

interface UsageGuardInterface
{
    public function reserve(
        Tenant $tenant,
        UsageCategory $category,
        int $quantity,
        ?string $idempotencyKey = null,
        ?int $ttlSeconds = null,
    ): ?UsageReservation;

    public function commit(UsageReservation $reservation): UsageRecord;

    public function commitWithActual(UsageReservation $reservation, int $actualQuantity): UsageRecord;

    public function recordDirect(
        Tenant $tenant,
        UsageCategory $category,
        int $quantity,
        ?string $description = null,
    ): UsageRecord;

    public function release(UsageReservation $reservation): void;

    public function remaining(Tenant $tenant, UsageCategory $category): ?int;
}
