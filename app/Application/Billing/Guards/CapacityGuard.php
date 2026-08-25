<?php

declare(strict_types=1);

namespace App\Application\Billing\Guards;

use App\Domain\Billing\Contracts\CapacityCheckInterface;
use App\Domain\Billing\Contracts\CapacityGuardInterface;
use App\Domain\Billing\Enums\UsageCategory;
use App\Domain\Billing\Exceptions\TenantQuotaExceededException;
use App\Domain\Contacts\Models\Contact;
use App\Domain\KnowledgeBase\Models\KnowledgeDocument;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\TenantMembershipStatus;
use App\Domain\Users\Models\TenantUser;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Serializes current-capacity checks by tenant and category.
 *
 * Capacity is derived from current domain rows, never periodic usage records.
 */
final class CapacityGuard implements CapacityGuardInterface
{
    public function __construct(private readonly EntitlementResolver $entitlementResolver) {}

    /**
     * @template T
     *
     * @param  Closure(CapacityCheckInterface): T  $operation
     * @return T
     */
    public function withinLock(Tenant $tenant, UsageCategory $category, Closure $operation): mixed
    {
        $this->assertCapacityCategory($category);
        $lockKey = $this->computeLockKey($tenant->id, $category);

        return DB::transaction(function () use ($tenant, $category, $operation, $lockKey): mixed {
            $this->acquireAdvisoryLock($lockKey);

            $check = new CapacityCheck(
                fn (): null => $this->assertCanCreateLocked($tenant, $category),
            );

            return $operation($check);
        });
    }

    public function assertCanCreate(Tenant $tenant, UsageCategory $category): void
    {
        $this->withinLock(
            $tenant,
            $category,
            static function (CapacityCheckInterface $check): void {
                $check->assertCanCreate();
            },
        );
    }

    private function assertCanCreateLocked(Tenant $tenant, UsageCategory $category): null
    {
        $entitlement = $this->entitlementResolver->resolve($tenant);
        $plan = $entitlement[1];
        $limit = $plan->getLimit($category->value);

        if ($limit === null) {
            return null;
        }

        $used = $this->currentUsed($tenant->id, $category);

        if ($used >= $limit) {
            throw TenantQuotaExceededException::forQuota($category->value, $limit, $used);
        }

        return null;
    }

    private function currentUsed(string $tenantId, UsageCategory $category): int
    {
        return match ($category) {
            UsageCategory::Contacts => Contact::query()
                ->withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->count(),
            UsageCategory::Users => TenantUser::query()
                ->where('tenant_id', $tenantId)
                ->where('status', TenantMembershipStatus::Active)
                ->count(),
            UsageCategory::KnowledgeDocuments => KnowledgeDocument::query()
                ->withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->count(),
            default => throw new InvalidArgumentException(
                "Category [{$category->value}] is periodic usage, not current capacity.",
            ),
        };
    }

    private function assertCapacityCategory(UsageCategory $category): void
    {
        if (! in_array($category, [
            UsageCategory::Contacts,
            UsageCategory::Users,
            UsageCategory::KnowledgeDocuments,
        ], true)) {
            throw new InvalidArgumentException(
                "Category [{$category->value}] is periodic usage, not current capacity.",
            );
        }
    }

    private function computeLockKey(string $tenantId, UsageCategory $category): int
    {
        return crc32("capacity:{$tenantId}:{$category->value}");
    }

    private function acquireAdvisoryLock(int $lockKey): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(CAST(? AS bigint))', [$lockKey]);
        }
    }
}
