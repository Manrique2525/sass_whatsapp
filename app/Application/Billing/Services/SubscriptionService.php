<?php

declare(strict_types=1);

namespace App\Application\Billing\Services;

use App\Application\Audit\Services\AuditLogger;
use App\Application\Users\Services\AuthorizationService;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Exceptions\PlanNotFoundException;
use App\Domain\Billing\Exceptions\SubscriptionNotFoundException;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\TenantPermission;
use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Subscription lifecycle management (FASE 23 U3, ADR-090).
 *
 * Centralized mutations for plan assignment and cancellation.
 * Controllers are thin: they delegate all business logic here.
 *
 * Source of truth: subscriptions table.
 * Denormalized cache: tenants.plan_id — kept in sync within transactions.
 */
final class SubscriptionService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * List all active plans (global catalog).
     *
     * @return Collection<int, Plan>
     */
    public function listPlans(User $user, Tenant $tenant): Collection
    {
        $this->authorization->authorize($user, TenantPermission::ViewBilling, $tenant);

        return Plan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Show a single plan (global, authenticated).
     */
    public function showPlan(User $user, Tenant $tenant, string $planId): Plan
    {
        $this->authorization->authorize($user, TenantPermission::ViewBilling, $tenant);

        $plan = Plan::query()->whereKey($planId)->first();

        if ($plan === null) {
            throw new PlanNotFoundException;
        }

        return $plan;
    }

    /**
     * Get the current active subscription for the tenant.
     */
    public function currentSubscription(User $user, Tenant $tenant): ?Subscription
    {
        $this->authorization->authorize($user, TenantPermission::ViewBilling, $tenant);

        return Subscription::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('status', SubscriptionStatus::Active)
            ->with('plan')
            ->latest()
            ->first();
    }

    /**
     * Assign a plan to the tenant (create or replace subscription).
     *
     * If an active subscription already exists, it is cancelled (soft-deleted)
     * and a new one is created in the same transaction.
     *
     * @throws PlanNotFoundException
     */
    public function assignPlan(User $user, Tenant $tenant, string $planId): Subscription
    {
        $this->authorization->authorize($user, TenantPermission::ManageBilling, $tenant);

        $plan = Plan::query()->whereKey($planId)->first();

        if ($plan === null) {
            throw new PlanNotFoundException;
        }

        return DB::transaction(function () use ($tenant, $plan): Subscription {
            // Cancel any existing active subscription
            $existing = Subscription::query()
                ->withoutTenantScope()
                ->where('tenant_id', $tenant->id)
                ->where('status', SubscriptionStatus::Active)
                ->latest()
                ->first();

            if ($existing !== null) {
                $existing->update(['status' => SubscriptionStatus::Cancelled]);
                $existing->delete(); // soft delete
            }

            $subscription = Subscription::create([
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'status' => SubscriptionStatus::Active,
                'quantity' => 1,
            ]);

            // Sync denormalized cache
            $tenant->update(['plan_id' => $plan->id]);

            $this->auditLogger->record(
                action: 'billing.subscription.assigned',
                data: [
                    'tenant_id' => $tenant->id,
                    'plan_id' => $plan->id,
                    'plan_slug' => $plan->slug,
                ],
                subjectType: Subscription::class,
                subjectId: $subscription->id,
            );

            return $subscription->fresh('plan');
        });
    }

    /**
     * Change the plan for an existing active subscription.
     *
     * Validates the new plan is different from the current one.
     * Updates tenants.plan_id within the same transaction.
     *
     * @throws PlanNotFoundException
     * @throws SubscriptionNotFoundException
     */
    public function changePlan(User $user, Tenant $tenant, string $planId): Subscription
    {
        $this->authorization->authorize($user, TenantPermission::ManageBilling, $tenant);

        $plan = Plan::query()->whereKey($planId)->first();

        if ($plan === null) {
            throw new PlanNotFoundException;
        }

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

        if ($subscription->plan_id === $plan->id) {
            return $subscription->fresh('plan');
        }

        return DB::transaction(function () use ($subscription, $plan, $tenant): Subscription {
            $subscription->update(['plan_id' => $plan->id]);

            // Sync denormalized cache
            $tenant->update(['plan_id' => $plan->id]);

            $this->auditLogger->record(
                action: 'billing.subscription.plan_changed',
                data: [
                    'tenant_id' => $tenant->id,
                    'old_plan_id' => $subscription->plan_id,
                    'new_plan_id' => $plan->id,
                    'new_plan_slug' => $plan->slug,
                ],
                subjectType: Subscription::class,
                subjectId: $subscription->id,
            );

            return $subscription->fresh('plan');
        });
    }

    /**
     * Cancel the active subscription (soft-delete + status=cancelled).
     *
     * tenants.plan_id is set to null.
     *
     * @throws SubscriptionNotFoundException
     */
    public function cancel(User $user, Tenant $tenant): void
    {
        $this->authorization->authorize($user, TenantPermission::ManageBilling, $tenant);

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

        DB::transaction(function () use ($subscription, $tenant): void {
            $planId = $subscription->plan_id;

            $subscription->update(['status' => SubscriptionStatus::Cancelled]);
            $subscription->delete(); // soft delete

            // Clear denormalized cache
            $tenant->update(['plan_id' => null]);

            $this->auditLogger->record(
                action: 'billing.subscription.cancelled',
                data: [
                    'tenant_id' => $tenant->id,
                    'plan_id' => $planId,
                ],
                subjectType: Subscription::class,
                subjectId: $subscription->id,
            );
        });
    }
}
