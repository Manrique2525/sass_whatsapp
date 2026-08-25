<?php

declare(strict_types=1);

namespace App\Domain\Billing\Contracts;

use App\Domain\Billing\Enums\UsageCategory;
use App\Domain\Tenants\Models\Tenant;
use Closure;

interface CapacityGuardInterface
{
    /**
     * @template T
     *
     * @param  Closure(CapacityCheckInterface): T  $operation
     * @return T
     */
    public function withinLock(Tenant $tenant, UsageCategory $category, Closure $operation): mixed;

    public function assertCanCreate(Tenant $tenant, UsageCategory $category): void;
}
