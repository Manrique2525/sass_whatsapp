<?php

declare(strict_types=1);

namespace App\Application\Billing\Guards;

use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Tenants\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * Resolves the subscription + plan entitlement for a tenant.
 *
 * Entitlement policy: Active + PastDue = allowed. Pending/Cancelled = fail-closed.
 * Missing subscription = fail-closed.
 * cancel_at_period_end = still active for current period.
 */
final class EntitlementResolver
{
    /**
     * @return array{0: Subscription, 1: Plan, 2: Carbon, 3: Carbon}
     *
     * @throws \App\Domain\Billing\Exceptions\SubscriptionNotFoundException
     */
    public function resolve(Tenant $tenant): array
    {
        $subscription = Subscription::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::PastDue])
            ->latest()
            ->first();

        if ($subscription === null) {
            throw new \App\Domain\Billing\Exceptions\SubscriptionNotFoundException(
                "No active or past-due subscription found for tenant [{$tenant->id}].",
            );
        }

        /** @var Plan $plan */
        $plan = $subscription->plan;

        [$periodStart, $periodEnd] = $this->resolveCurrentPeriod($subscription);

        return [$subscription, $plan, $periodStart, $periodEnd];
    }

    /**
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

        $now = now();

        return [
            $now->copy()->startOfMonth()->startOfMinute(),
            $now->copy()->addMonth()->startOfMonth()->startOfMinute(),
        ];
    }
}
