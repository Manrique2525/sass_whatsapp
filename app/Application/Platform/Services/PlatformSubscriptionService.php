<?php

declare(strict_types=1);

namespace App\Application\Platform\Services;

use App\Application\Billing\Services\SubscriptionService;
use App\Application\Billing\Services\UsageTrackingService;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Enums\UsageCategory;
use App\Domain\Billing\Exceptions\SubscriptionNotFoundException;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\ValueObjects\UsageSummary;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PlatformSubscriptionService
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly UsageTrackingService $usage,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $query = $this->subscriptionQuery();
        $this->applyFilters($query, $filters);

        /** @var LengthAwarePaginator<int, object> $paginator */
        $paginator = $query
            ->orderByDesc('subscriptions.created_at')
            ->orderByDesc('subscriptions.id')
            ->paginate((int) ($filters['per_page'] ?? 20));

        return $paginator->through(fn (object $row): array => $this->summary($row));
    }

    /** @return list<array{id: string, name: string, slug: string, price_monthly: string, price_yearly: string, limits: array<string, int|null>, features: array<string, bool>}> */
    public function activePlans(): array
    {
        return Plan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'price_monthly', 'price_yearly'])
            ->map(static fn (Plan $plan): array => [
                'id' => $plan->id,
                'name' => $plan->name,
                'slug' => $plan->slug,
                'price_monthly' => (string) $plan->price_monthly,
                'price_yearly' => (string) $plan->price_yearly,
                'limits' => $plan->limits ?? [],
                'features' => $plan->features ?? [],
            ])->all();
    }

    /** @return array<string, mixed> */
    public function show(string $subscriptionId): array
    {
        $subscription = Subscription::query()
            ->withoutTenantScope()
            ->whereKey($subscriptionId)
            ->whereNull('deleted_at')
            ->with(['tenant', 'plan'])
            ->first();

        if ($subscription === null) {
            throw new SubscriptionNotFoundException('Subscription not found.');
        }

        /** @var Tenant $tenant */
        $tenant = $subscription->tenant;
        /** @var Plan $plan */
        $plan = $subscription->plan;

        return [
            'subscription' => $this->subscriptionData($subscription, $tenant, $plan),
            'usage' => $this->usage($tenant),
            'plans' => $this->activePlans(),
            'audit' => $this->audit($subscription),
        ];
    }

    /** @return array<string, mixed> */
    public function changeForm(Tenant $tenant): array
    {
        $subscription = $this->currentSubscription($tenant);
        $plans = $this->activePlans();
        $usage = $this->usage($tenant);

        return [
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->name, 'slug' => $tenant->slug],
            'subscription' => $subscription === null ? null : $this->subscriptionData($subscription, $tenant, $subscription->plan),
            'usage' => $usage,
            'plans' => $plans,
        ];
    }

    /** @return array<string, mixed> */
    public function preview(Tenant $tenant, string $planId): array
    {
        $subscription = $this->currentSubscription($tenant);
        if ($subscription === null) {
            throw new SubscriptionNotFoundException('No active subscription found for this tenant.');
        }

        $target = Plan::query()->whereKey($planId)->where('is_active', true)->first();
        if ($target === null) {
            throw ValidationException::withMessages(['plan_id' => 'The target plan must be active and available.']);
        }

        /** @var Plan $current */
        $current = $subscription->plan;
        $usage = $this->usage($tenant);

        return $this->previewData($current, $target, $usage);
    }

    public function changePlan(User $actor, Tenant $tenant, string $planId, string $reason): Subscription
    {
        return $this->subscriptions->changePlanAsPlatformAdmin($actor, $tenant, $planId, $reason);
    }

    public function currentSubscription(Tenant $tenant): ?Subscription
    {
        return Subscription::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('status', SubscriptionStatus::Active)
            ->whereNull('deleted_at')
            ->with('plan')
            ->latest('created_at')
            ->latest('id')
            ->first();
    }

    private function subscriptionQuery(): Builder
    {
        return DB::table('subscriptions')
            ->join('tenants', 'tenants.id', '=', 'subscriptions.tenant_id')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->whereNull('subscriptions.deleted_at')
            ->select([
                'subscriptions.id', 'subscriptions.tenant_id', 'subscriptions.plan_id', 'subscriptions.status',
                'subscriptions.stripe_subscription_id', 'subscriptions.created_at', 'subscriptions.updated_at',
                'subscriptions.current_period_start', 'subscriptions.current_period_end',
                'subscriptions.cancel_at_period_end', 'tenants.name as tenant_name', 'tenants.slug as tenant_slug',
                'plans.name as plan_name', 'plans.slug as plan_slug', 'plans.price_monthly', 'plans.price_yearly',
            ])
            ->selectSub($this->ownerQuery(), 'owner_name')
            ->selectSub($this->ownerQuery()->select('owners.email'), 'owner_email');
    }

    private function ownerQuery(): Builder
    {
        return DB::table('tenant_users as owner_memberships')
            ->join('users as owners', 'owners.id', '=', 'owner_memberships.user_id')
            ->whereColumn('owner_memberships.tenant_id', 'subscriptions.tenant_id')
            ->where('owner_memberships.role', 'owner')
            ->where('owner_memberships.status', 'active')
            ->orderBy('owners.id')
            ->limit(1)
            ->select('owners.name');
    }

    /** @param array<string, mixed> $filters */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (($filters['search'] ?? '') !== '') {
            $search = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], (string) $filters['search']).'%';
            $query->where(function (Builder $nested) use ($search): void {
                $nested->whereRaw("LOWER(tenants.name) LIKE LOWER(?) ESCAPE '!'", [$search])
                    ->orWhereRaw("LOWER(tenants.slug) LIKE LOWER(?) ESCAPE '!'", [$search]);
            });
        }

        foreach (['tenant_id', 'plan_id', 'status'] as $field) {
            if (($filters[$field] ?? '') !== '') {
                $query->where('subscriptions.'.$field, $filters[$field]);
            }
        }

        if (($filters['provider'] ?? '') === 'stripe') {
            $query->whereNotNull('subscriptions.stripe_subscription_id');
        } elseif (($filters['provider'] ?? '') === 'local') {
            $query->whereNull('subscriptions.stripe_subscription_id');
        }
    }

    /** @return array<string, mixed> */
    private function summary(object $row): array
    {
        $tenant = Tenant::query()->find($row->tenant_id);

        return [
            'id' => $row->id,
            'tenant' => ['id' => $row->tenant_id, 'name' => $row->tenant_name, 'slug' => $row->tenant_slug],
            'owner' => ['name' => $row->owner_name, 'email' => $row->owner_email],
            'plan' => ['id' => $row->plan_id, 'name' => $row->plan_name, 'slug' => $row->plan_slug],
            'status' => $row->status,
            'provider' => $row->stripe_subscription_id === null ? 'local' : 'stripe',
            'provider_subscription_id' => self::maskProviderId($row->stripe_subscription_id),
            'current_period_start' => $row->current_period_start,
            'current_period_end' => $row->current_period_end,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
            'usage' => $tenant === null ? null : $this->usage($tenant),
        ];
    }

    /** @return array<string, mixed> */
    private function subscriptionData(Subscription $subscription, Tenant $tenant, Plan $plan): array
    {
        $status = (string) $subscription->getRawOriginal('status');

        return [
            'id' => $subscription->id,
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->name, 'slug' => $tenant->slug],
            'plan' => ['id' => $plan->id, 'name' => $plan->name, 'slug' => $plan->slug, 'price_monthly' => (string) $plan->price_monthly, 'price_yearly' => (string) $plan->price_yearly],
            'status' => $status,
            'started_at' => self::dateValue($subscription->getRawOriginal('created_at')),
            'current_period_start' => self::dateValue($subscription->getRawOriginal('current_period_start')),
            'current_period_end' => self::dateValue($subscription->getRawOriginal('current_period_end')),
            'cancel_at_period_end' => (bool) $subscription->cancel_at_period_end,
            'provider' => $subscription->isProviderManaged() ? 'stripe' : 'local',
            'provider_subscription_id' => self::maskProviderId($subscription->stripe_subscription_id),
        ];
    }

    /** @return array<string, mixed>|null */
    private function usage(Tenant $tenant): ?array
    {
        try {
            $summary = $this->usage->currentPeriodSummary($tenant);
        } catch (SubscriptionNotFoundException) {
            return null;
        }

        return $this->usageData($summary);
    }

    /** @return array<string, mixed> */
    private function usageData(UsageSummary $summary): array
    {
        $categories = [];
        foreach ($summary->categories as $category => $values) {
            $categories[$category] = [
                'used' => $values->used,
                'limit' => $values->limit,
                'remaining' => $values->remaining,
                'percentage' => $values->limit === null || $values->limit === 0 ? null : min(100, round(($values->used / $values->limit) * 100, 1)),
            ];
        }

        return ['period_start' => $summary->periodStart, 'period_end' => $summary->periodEnd, 'categories' => $categories];
    }

    /**
     * @param  array{categories?: array<string, array{used?: int}>}|null  $usage
     * @return array<string, mixed>
     */
    private function previewData(Plan $current, Plan $target, ?array $usage): array
    {
        $usageCategories = $usage['categories'] ?? [];
        $limits = [];
        foreach (UsageCategory::cases() as $case) {
            $category = $case->value;
            $currentLimit = $current->getLimit($category);
            $targetLimit = $target->getLimit($category);
            $used = 0;
            if (array_key_exists($category, $usageCategories)) {
                $used = (int) ($usageCategories[$category]['used'] ?? 0);
            }
            $limits[$category] = [
                'used' => $used, 'current_limit' => $currentLimit, 'target_limit' => $targetLimit,
                'over_limit' => $targetLimit !== null && $used > $targetLimit,
            ];
        }

        return [
            'current_plan' => ['id' => $current->id, 'name' => $current->name, 'slug' => $current->slug],
            'target_plan' => ['id' => $target->id, 'name' => $target->name, 'slug' => $target->slug, 'price_monthly' => (string) $target->price_monthly, 'price_yearly' => (string) $target->price_yearly],
            'features' => ['ai_enabled' => ['from' => $current->hasFeature('ai_enabled'), 'to' => $target->hasFeature('ai_enabled')]],
            'limits' => $limits,
            'has_over_limit' => collect($limits)->contains(static fn (array $item): bool => $item['over_limit']),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function audit(Subscription $subscription): array
    {
        return DB::table('audit_logs')->leftJoin('users', 'users.id', '=', 'audit_logs.actor_user_id')
            ->where('audit_logs.subject_type', Subscription::class)
            ->where('audit_logs.subject_id', $subscription->id)
            ->orderByDesc('audit_logs.created_at')->orderByDesc('audit_logs.id')->limit(50)
            ->get(['audit_logs.id', 'audit_logs.action', 'audit_logs.created_at', 'users.name as actor_name'])
            ->map(static fn (object $row): array => [
                'id' => $row->id, 'action' => $row->action, 'created_at' => $row->created_at, 'actor_name' => $row->actor_name,
            ])->all();
    }

    private static function maskProviderId(?string $id): ?string
    {
        if ($id === null || strlen($id) <= 8) {
            return $id;
        }

        return substr($id, 0, 4).'...'.substr($id, -4);
    }

    private static function dateValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof Carbon ? $value->toIso8601String() : Carbon::parse((string) $value)->toIso8601String();
    }
}
