<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Domain\Billing\Contracts\CapacityCheckInterface;
use App\Domain\Billing\Contracts\CapacityGuardInterface;
use App\Domain\Billing\Enums\UsageCategory;
use App\Domain\Tenants\Models\Tenant;
use Closure;

final class FakeCapacityGuard implements CapacityGuardInterface
{
    /**
     * @template T
     *
     * @param  Closure(CapacityCheckInterface): T  $operation
     * @return T
     */
    public function withinLock(Tenant $tenant, UsageCategory $category, Closure $operation): mixed
    {
        return $operation(new class implements CapacityCheckInterface
        {
            public function assertCanCreate(): void {}
        });
    }

    public function assertCanCreate(Tenant $tenant, UsageCategory $category): void {}
}
