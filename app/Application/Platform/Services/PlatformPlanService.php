<?php

declare(strict_types=1);

namespace App\Application\Platform\Services;

use App\Application\Audit\Services\AuditLogger;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Enums\UsageCategory;
use App\Domain\Billing\Models\Plan;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PlatformPlanService
{
    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): Plan
    {
        return DB::transaction(function () use ($attributes): Plan {
            if (($attributes['slug'] ?? null) === 'free') {
                throw ValidationException::withMessages(['slug' => 'The canonical Free plan cannot be recreated.']);
            }

            $plan = Plan::query()->create($this->attributes($attributes));
            $this->audit('platform.plan.created', $plan, null, $this->snapshot($plan), null);

            return $plan;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(Plan $plan, array $attributes): Plan
    {
        return DB::transaction(function () use ($plan, $attributes): Plan {
            $before = $this->snapshot($plan);
            $reason = trim((string) ($attributes['reason'] ?? ''));
            $next = $this->attributes($attributes);

            if (($next['slug'] ?? $plan->slug) !== $plan->slug) {
                throw ValidationException::withMessages(['slug' => 'Plan slugs are immutable after creation.']);
            }

            if ($plan->slug === 'free' && (
                ($next['name'] ?? 'Free') !== 'Free'
                || ! $next['is_active']
                || (float) $next['price_monthly'] !== 0.0
                || (float) $next['price_yearly'] !== 0.0
                || ($next['features'] ?? $before['features']) !== $before['features']
                || ($next['limits'] ?? $before['limits']) !== $before['limits']
            )) {
                throw ValidationException::withMessages(['slug' => 'The Free plan must remain active with zero prices and its canonical slug.']);
            }

            $priceChanged = number_format((float) ($next['price_monthly'] ?? $before['price_monthly']), 2, '.', '') !== $before['price_monthly']
                || number_format((float) ($next['price_yearly'] ?? $before['price_yearly']), 2, '.', '') !== $before['price_yearly']
                || ($next['stripe_price_id_monthly'] ?? $before['stripe_price_id_monthly']) !== $before['stripe_price_id_monthly']
                || ($next['stripe_price_id_yearly'] ?? $before['stripe_price_id_yearly']) !== $before['stripe_price_id_yearly'];
            $featuresChanged = ($next['features'] ?? $before['features']) !== $before['features'];
            $limitsChanged = ($next['limits'] ?? $before['limits']) !== $before['limits'];
            $statusChanged = (bool) ($next['is_active'] ?? $before['is_active']) !== $before['is_active'];

            if (($priceChanged || $featuresChanged || $limitsChanged || $statusChanged) && $reason === '') {
                throw ValidationException::withMessages(['reason' => 'A reason is required for pricing, feature, limit, or status changes.']);
            }

            if ($priceChanged && $this->activeSubscriberCount($plan) > 0) {
                throw ValidationException::withMessages(['price_monthly' => 'Plans with subscribers cannot be repriced or remapped. Create a new plan version instead.']);
            }

            $plan->fill($next)->save();
            $after = $this->snapshot($plan->fresh());

            $this->audit('platform.plan.updated', $plan, $before, $after, $reason);
            if ($before['is_active'] !== $after['is_active']) {
                $this->audit($after['is_active'] ? 'platform.plan.activated' : 'platform.plan.deactivated', $plan, $before, $after, $reason);
            }
            if ($before['features'] !== $after['features']) {
                $this->audit('platform.plan.features_changed', $plan, $before, $after, $reason);
            }
            if ($before['limits'] !== $after['limits']) {
                $this->audit('platform.plan.limits_changed', $plan, $before, $after, $reason);
            }
            if ($before['price_monthly'] !== $after['price_monthly'] || $before['price_yearly'] !== $after['price_yearly']
                || $before['stripe_price_id_monthly'] !== $after['stripe_price_id_monthly']
                || $before['stripe_price_id_yearly'] !== $after['stripe_price_id_yearly']) {
                $this->audit('platform.plan.price_changed', $plan, $before, $after, $reason);
            }

            return $plan->fresh();
        });
    }

    /** @return array<string, mixed> */
    public function present(Plan $plan): array
    {
        $snapshot = $this->snapshot($plan);
        $audit = DB::table('audit_logs')->leftJoin('users', 'users.id', '=', 'audit_logs.actor_user_id')
            ->where('audit_logs.subject_type', Plan::class)->where('audit_logs.subject_id', $plan->id)
            ->orderByDesc('audit_logs.created_at')->orderByDesc('audit_logs.id')->limit(50)
            ->get(['audit_logs.id', 'audit_logs.action', 'audit_logs.data', 'audit_logs.created_at', 'users.name as actor_name'])
            ->map(static fn (object $row): array => [
                'id' => $row->id, 'action' => $row->action, 'metadata' => is_string($row->data) ? json_decode($row->data, true) : $row->data,
                'created_at' => $row->created_at, 'actor_name' => $row->actor_name,
            ])->all();

        return [...$snapshot, 'impact' => [
            'active_subscriptions' => $this->subscriptionCount($plan, SubscriptionStatus::Active),
            'past_due_subscriptions' => $this->subscriptionCount($plan, SubscriptionStatus::PastDue),
        ], 'audit' => $audit];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function summaries(): array
    {
        return Plan::query()
            ->select('plans.*')
            ->selectSub($this->subscriptionCountQuery(SubscriptionStatus::Active), 'active_subscriptions_count')
            ->selectSub($this->subscriptionCountQuery(SubscriptionStatus::PastDue), 'past_due_subscriptions_count')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(function (Plan $plan): array {
                return [...$this->snapshot($plan), 'impact' => [
                    'active_subscriptions' => (int) $plan->getAttribute('active_subscriptions_count'),
                    'past_due_subscriptions' => (int) $plan->getAttribute('past_due_subscriptions_count'),
                ]];
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function attributes(array $attributes): array
    {
        $allowed = array_intersect_key($attributes, array_flip([
            'slug', 'name', 'description', 'is_active', 'price_monthly', 'price_yearly',
            'stripe_price_id_monthly', 'stripe_price_id_yearly', 'sort_order',
        ]));

        if (array_key_exists('limits', $attributes)) {
            $allowed['limits'] = array_intersect_key(
                (array) $attributes['limits'],
                array_flip(array_map(static fn (UsageCategory $category): string => $category->value, UsageCategory::cases())),
            );
        }

        if (array_key_exists('features', $attributes)) {
            $allowed['features'] = array_intersect_key((array) $attributes['features'], ['ai_enabled' => true]);
        }

        return $allowed;
    }

    /** @return array<string, mixed> */
    private function snapshot(Plan $plan): array
    {
        return [
            'id' => $plan->id, 'slug' => $plan->slug, 'name' => $plan->name, 'description' => $plan->description,
            'is_active' => (bool) $plan->is_active, 'price_monthly' => (string) $plan->price_monthly,
            'price_yearly' => (string) $plan->price_yearly, 'stripe_price_id_monthly' => $plan->stripe_price_id_monthly,
            'stripe_price_id_yearly' => $plan->stripe_price_id_yearly, 'limits' => $plan->limits ?? [],
            'features' => $plan->features ?? [], 'sort_order' => (int) $plan->sort_order,
        ];
    }

    private function subscriptionCount(Plan $plan, SubscriptionStatus $status): int
    {
        return $this->activeSubscriberCount($plan, $status);
    }

    private function activeSubscriberCount(Plan $plan, ?SubscriptionStatus $status = null): int
    {
        $query = $plan->subscriptions()->withoutTenantScope()->whereNull('subscriptions.deleted_at');

        if ($status !== null) {
            $query->where('subscriptions.status', $status->value);
        } else {
            $query->whereIn('subscriptions.status', [SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value]);
        }

        return $query->count();
    }

    private function subscriptionCountQuery(SubscriptionStatus $status): Builder
    {
        return DB::table('subscriptions')
            ->whereColumn('subscriptions.plan_id', 'plans.id')
            ->where('subscriptions.status', $status->value)
            ->whereNull('subscriptions.deleted_at')
            ->selectRaw('COUNT(*)');
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $after
     */
    private function audit(string $action, Plan $plan, ?array $before, array $after, ?string $reason): void
    {
        app(AuditLogger::class)->record($action, [
            'before' => $before,
            'after' => $after,
            ...($reason !== null && $reason !== '' ? ['reason' => $reason] : []),
        ], Plan::class, $plan->id, platform: true);
    }
}
