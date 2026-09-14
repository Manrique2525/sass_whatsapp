<?php

declare(strict_types=1);

namespace App\Application\Platform\Services;

use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Tenants\Enums\TenantStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Read-only, platform-global dashboard queries.
 *
 * This service deliberately uses explicit query-builder aggregates instead of
 * tenant-scoped models. No result contains message, contact, knowledge or
 * provider-secret data.
 */
final class PlatformDashboardQueryService
{
    /** @return array<string, mixed> */
    public function overview(): array
    {
        $now = now();
        $periodStart = $now->copy()->startOfMonth();
        $periodEnd = $periodStart->copy()->addMonth();

        return [
            'summary' => $this->summary($periodStart, $periodEnd),
            'trends' => ['new_customers' => $this->newCustomerTrend($now)],
            'plans' => $this->planDistribution(),
            'subscriptions' => [
                'statuses' => $this->subscriptionStatuses(),
                'providers' => $this->providerDistribution(),
            ],
            'usage' => $this->usage($periodStart, $periodEnd),
            'whatsapp' => $this->whatsappDistribution(),
            'operations' => [
                'alerts' => $this->alerts(),
                'recent_customers' => $this->recentCustomers(),
                'recent_activity' => $this->recentActivity(),
            ],
        ];
    }

    /** @return array<string, int> */
    private function summary(Carbon $periodStart, Carbon $periodEnd): array
    {
        $activeSubscriptions = DB::table('subscriptions')
            ->whereNull('deleted_at')
            ->where('status', SubscriptionStatus::Active->value);

        return [
            'total_tenants' => (int) DB::table('tenants')->count(),
            'active_tenants' => (int) DB::table('tenants')->where('status', TenantStatus::Active->value)->count(),
            'new_tenants_period' => (int) DB::table('tenants')->where('created_at', '>=', $periodStart)->where('created_at', '<', $periodEnd)->count(),
            'unique_users' => (int) DB::table('users')->count(),
            'tenant_memberships' => (int) DB::table('tenant_users')->where('status', 'active')->count(),
            'total_subscriptions' => (int) DB::table('subscriptions')->whereNull('deleted_at')->count(),
            'active_subscriptions' => (int) $activeSubscriptions->count(),
            'free_tenants' => (int) $activeSubscriptions->join('plans', 'plans.id', '=', 'subscriptions.plan_id')->where('plans.slug', 'free')->count(),
            'whatsapp_configured' => (int) DB::table('whatsapp_accounts')->count(),
            'whatsapp_connected' => (int) DB::table('whatsapp_accounts')->where('status', 'connected')->count(),
        ];
    }

    /** @return list<array{period: string, count: int}> */
    private function newCustomerTrend(Carbon $now): array
    {
        $start = $now->copy()->startOfMonth()->subMonths(5);
        $monthExpression = DB::getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', created_at)"
            : "TO_CHAR(DATE_TRUNC('month', created_at), 'YYYY-MM')";
        $counts = DB::table('tenants')
            ->where('created_at', '>=', $start)
            ->selectRaw("{$monthExpression} AS period, COUNT(*) AS count")
            ->groupByRaw($monthExpression)
            ->orderBy('period')
            ->pluck('count', 'period');

        $trend = [];
        for ($index = 0; $index < 6; $index++) {
            $period = $start->copy()->addMonths($index);
            $key = $period->format('Y-m');
            $trend[] = ['period' => $period->format('Y-m'), 'count' => (int) ($counts[$key] ?? 0)];
        }

        return $trend;
    }

    /** @return list<array{name: string, slug: string, plan_id: string, subscribers: int, is_active: bool}> */
    private function planDistribution(): array
    {
        return DB::table('plans')
            ->leftJoin('subscriptions', function ($join): void {
                $join->on('subscriptions.plan_id', '=', 'plans.id')
                    ->whereNull('subscriptions.deleted_at')
                    ->where('subscriptions.status', SubscriptionStatus::Active->value);
            })
            ->select('plans.id as plan_id', 'plans.name', 'plans.slug', 'plans.is_active')
            ->selectRaw('COUNT(subscriptions.id) AS subscribers')
            ->groupBy('plans.id', 'plans.name', 'plans.slug', 'plans.is_active', 'plans.sort_order')
            ->orderBy('plans.sort_order')->orderBy('plans.name')
            ->get()
            ->map(static fn (object $row): array => [
                'plan_id' => $row->plan_id,
                'name' => $row->name,
                'slug' => $row->slug,
                'subscribers' => (int) $row->subscribers,
                'is_active' => (bool) $row->is_active,
            ])->all();
    }

    /** @return list<array{status: string, count: int}> */
    private function subscriptionStatuses(): array
    {
        return DB::table('subscriptions')->whereNull('deleted_at')
            ->select('status')->selectRaw('COUNT(*) AS count')->groupBy('status')->orderBy('status')->get()
            ->map(static fn (object $row): array => ['status' => $row->status, 'count' => (int) $row->count])->all();
    }

    /** @return list<array{provider: string, count: int}> */
    private function providerDistribution(): array
    {
        return DB::table('subscriptions')->whereNull('deleted_at')
            ->selectRaw("CASE WHEN stripe_subscription_id IS NULL THEN 'local' ELSE 'stripe' END AS provider")
            ->selectRaw('COUNT(*) AS count')->groupByRaw("CASE WHEN stripe_subscription_id IS NULL THEN 'local' ELSE 'stripe' END")
            ->orderBy('provider')->get()
            ->map(static fn (object $row): array => ['provider' => $row->provider, 'count' => (int) $row->count])->all();
    }

    /** @return array{period_start: string, period_end: string, categories: list<array{category: string, quantity: int}>} */
    private function usage(Carbon $periodStart, Carbon $periodEnd): array
    {
        $rows = DB::table('usage_records')
            ->join('subscriptions', 'subscriptions.id', '=', 'usage_records.subscription_id')
            ->whereNull('subscriptions.deleted_at')
            ->where('subscriptions.status', SubscriptionStatus::Active->value)
            ->whereRaw('usage_records.recorded_at >= COALESCE(subscriptions.current_period_start, ?)', [$periodStart])
            ->whereRaw('usage_records.recorded_at < COALESCE(subscriptions.current_period_end, ?)', [$periodEnd])
            ->select('usage_records.category')->selectRaw('SUM(usage_records.quantity) AS quantity')
            ->groupBy('usage_records.category')->orderBy('usage_records.category')->get();

        return [
            'period_start' => $periodStart->toIso8601String(),
            'period_end' => $periodEnd->toIso8601String(),
            'categories' => $rows->map(static fn (object $row): array => ['category' => $row->category, 'quantity' => (int) $row->quantity])->all(),
        ];
    }

    /** @return array<string, int> */
    private function whatsappDistribution(): array
    {
        return [
            'configured' => (int) DB::table('whatsapp_accounts')->count(),
            'connected' => (int) DB::table('whatsapp_accounts')->where('status', 'connected')->count(),
            'disconnected' => (int) DB::table('whatsapp_accounts')->where('status', 'disconnected')->count(),
            'connected_phone_numbers' => (int) DB::table('whatsapp_phone_numbers')->where('status', 'connected')->count(),
            'banned_phone_numbers' => (int) DB::table('whatsapp_phone_numbers')->where('status', 'banned')->count(),
        ];
    }

    /** @return list<array{type: string, count: int, label: string}> */
    private function alerts(): array
    {
        $alerts = [];
        $withoutSubscription = (int) DB::table('tenants')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('subscriptions')
                ->whereColumn('subscriptions.tenant_id', 'tenants.id')->whereNull('subscriptions.deleted_at')
                ->where('subscriptions.status', SubscriptionStatus::Active->value))->count();
        if ($withoutSubscription > 0) {
            $alerts[] = ['type' => 'missing_subscription', 'count' => $withoutSubscription, 'label' => 'Tenants without an active subscription'];
        }

        $pastDue = (int) DB::table('subscriptions')->whereNull('deleted_at')->where('status', SubscriptionStatus::PastDue->value)->count();
        if ($pastDue > 0) {
            $alerts[] = ['type' => 'past_due', 'count' => $pastDue, 'label' => 'Subscriptions past due'];
        }

        $disconnected = (int) DB::table('whatsapp_accounts')->where('status', 'disconnected')->count();
        if ($disconnected > 0) {
            $alerts[] = ['type' => 'whatsapp_disconnected', 'count' => $disconnected, 'label' => 'Configured WhatsApp accounts disconnected'];
        }

        $failedJobs = (int) DB::table('failed_jobs')->count();
        if ($failedJobs > 0) {
            $alerts[] = ['type' => 'failed_jobs', 'count' => $failedJobs, 'label' => 'Failed jobs recorded'];
        }

        return $alerts;
    }

    /** @return list<array<string, mixed>> */
    private function recentCustomers(): array
    {
        return DB::table('tenants')
            ->select(['tenants.id', 'tenants.name', 'tenants.created_at'])
            ->selectSub($this->ownerQuery(), 'owner_name')
            ->selectSub($this->ownerQuery()->select('owners.email'), 'owner_email')
            ->selectSub($this->activePlanQuery()->select('plans.id'), 'plan_id')
            ->selectSub($this->activePlanQuery()->select('plans.name'), 'plan_name')
            ->orderByDesc('tenants.created_at')->orderByDesc('tenants.id')->limit(8)->get()
            ->map(static fn (object $row): array => [
                'tenant' => ['id' => $row->id, 'name' => $row->name],
                'owner' => ['name' => $row->owner_name, 'email' => $row->owner_email],
                'plan' => $row->plan_id === null ? null : ['id' => $row->plan_id, 'name' => $row->plan_name],
                'created_at' => $row->created_at,
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function recentActivity(): array
    {
        $subscriptionId = DB::getDriverName() === 'pgsql'
            ? 'subscriptions.id::text'
            : 'CAST(subscriptions.id AS TEXT)';

        return DB::table('audit_logs')
            ->leftJoin('users', 'users.id', '=', 'audit_logs.actor_user_id')
            ->leftJoin('subscriptions', function ($join) use ($subscriptionId): void {
                $join->whereRaw("{$subscriptionId} = audit_logs.subject_id")
                    ->where('audit_logs.subject_type', 'App\\Domain\\Billing\\Models\\Subscription');
            })
            ->leftJoin('tenants', function ($join): void {
                $join->on('tenants.id', '=', 'audit_logs.tenant_id')
                    ->orOn('tenants.id', '=', 'subscriptions.tenant_id');
            })
            ->where('audit_logs.action', 'like', 'platform.%')
            ->select(['audit_logs.id', 'audit_logs.action', 'audit_logs.created_at', 'users.name as actor_name', 'tenants.id as tenant_id', 'tenants.name as tenant_name'])
            ->orderByDesc('audit_logs.created_at')->orderByDesc('audit_logs.id')->limit(8)->get()
            ->map(static fn (object $row): array => [
                'id' => $row->id,
                'action' => $row->action,
                'actor' => $row->actor_name,
                'target' => $row->tenant_id === null ? null : ['id' => $row->tenant_id, 'name' => $row->tenant_name],
                'created_at' => $row->created_at,
            ])->all();
    }

    private function ownerQuery(): Builder
    {
        return DB::table('tenant_users as owner_memberships')
            ->join('users as owners', 'owners.id', '=', 'owner_memberships.user_id')
            ->whereColumn('owner_memberships.tenant_id', 'tenants.id')
            ->where('owner_memberships.role', 'owner')->where('owner_memberships.status', 'active')
            ->orderBy('owners.id')->limit(1)->select('owners.name');
    }

    private function activePlanQuery(): Builder
    {
        return DB::table('subscriptions')->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->whereColumn('subscriptions.tenant_id', 'tenants.id')->whereNull('subscriptions.deleted_at')
            ->where('subscriptions.status', SubscriptionStatus::Active->value)
            ->orderByDesc('subscriptions.created_at')->orderByDesc('subscriptions.id')->limit(1);
    }
}
