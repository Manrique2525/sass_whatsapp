<?php

declare(strict_types=1);

namespace App\Application\Platform\Services;

use App\Application\Billing\Services\UsageTrackingService;
use App\Domain\Billing\Exceptions\SubscriptionNotFoundException;
use App\Domain\Billing\Models\Plan;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\TenantMembershipStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Explicit read-only query boundary for Platform Admin customer data.
 *
 * This is the only U3 location that performs cross-tenant reads. It uses
 * query-builder subqueries instead of tenant-scoped Eloquent models and only
 * selects fields intended for the platform presentation layer.
 */
final class PlatformCustomerQueryService
{
    public function __construct(private readonly UsageTrackingService $usageService) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $query = $this->customerQuery();
        $this->applyFilters($query, $filters);

        $sort = $filters['sort'] ?? 'created_at';
        $direction = $filters['direction'] ?? 'desc';
        $query->orderBy($sort === 'name' ? 'tenants.name' : 'tenants.created_at', $direction)
            ->orderBy('tenants.id');

        /** @var LengthAwarePaginator<int, object> $paginator */
        $paginator = $query->paginate((int) ($filters['per_page'] ?? 20));

        return $paginator->through(fn (object $row): array => $this->summary($row));
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    public function plans(): array
    {
        return Plan::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (Plan $plan): array => [
                'id' => $plan->id,
                'name' => $plan->name,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function show(string $tenantId): ?array
    {
        $row = $this->customerQuery()->where('tenants.id', $tenantId)->first();

        if ($row === null) {
            return null;
        }

        $members = DB::table('tenant_users')
            ->join('users', 'users.id', '=', 'tenant_users.user_id')
            ->where('tenant_users.tenant_id', $tenantId)
            ->select([
                'users.id',
                'users.name',
                'users.email',
                'tenant_users.role',
                'tenant_users.status',
                'tenant_users.joined_at',
            ])
            ->orderByRaw("CASE WHEN tenant_users.role = 'owner' THEN 0 ELSE 1 END")
            ->orderBy('users.name')
            ->get()
            ->map(static fn (object $member): array => [
                'id' => (int) $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'role' => $member->role,
                'status' => $member->status,
                'joined_at' => $member->joined_at,
            ])
            ->all();

        $subscription = DB::table('subscriptions')
            ->leftJoin('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->where('subscriptions.tenant_id', $tenantId)
            ->whereNull('subscriptions.deleted_at')
            ->select([
                'subscriptions.id',
                'subscriptions.status',
                'subscriptions.created_at',
                'subscriptions.current_period_start',
                'subscriptions.current_period_end',
                'subscriptions.cancel_at_period_end',
                'subscriptions.stripe_subscription_id',
                'plans.id as plan_id',
                'plans.name as plan_name',
                'plans.slug as plan_slug',
            ])
            ->latest('subscriptions.created_at')
            ->latest('subscriptions.id')
            ->first();

        $usage = $this->usage($tenantId);

        $whatsapp = DB::table('whatsapp_accounts')
            ->where('tenant_id', $tenantId)
            ->select(['id', 'display_name', 'status', 'updated_at'])
            ->first();

        $phones = DB::table('whatsapp_phone_numbers')
            ->where('tenant_id', $tenantId)
            ->select(['id', 'display_phone_number', 'verified_name', 'quality_rating', 'status', 'is_default'])
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get()
            ->map(static fn (object $phone): array => [
                'id' => $phone->id,
                'display_phone_number' => self::maskPhone($phone->display_phone_number),
                'verified_name' => $phone->verified_name,
                'quality_rating' => $phone->quality_rating,
                'status' => $phone->status,
                'is_default' => (bool) $phone->is_default,
            ])
            ->all();

        $audit = DB::table('audit_logs')
            ->leftJoin('users', 'users.id', '=', 'audit_logs.actor_user_id')
            ->where(function (Builder $auditQuery) use ($tenantId, $subscription): void {
                $auditQuery->where('audit_logs.tenant_id', $tenantId);
                if ($subscription !== null) {
                    $auditQuery->orWhere(function (Builder $platformAudit) use ($subscription): void {
                        $platformAudit->whereNull('audit_logs.tenant_id')
                            ->where('audit_logs.action', 'platform.subscription.plan_changed')
                            ->where('audit_logs.subject_id', $subscription->id);
                    });
                }
            })
            ->select([
                'audit_logs.id',
                'audit_logs.action',
                'audit_logs.subject_type',
                'audit_logs.subject_id',
                'audit_logs.created_at',
                'users.name as actor_name',
            ])
            ->latest('audit_logs.created_at')
            ->latest('audit_logs.id')
            ->paginate(15, ['*'], 'audit_page');

        return [
            'id' => $row->id,
            'name' => $row->name,
            'slug' => $row->slug,
            'status' => $row->status,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
            'owner' => $row->owner_id !== null ? [
                'id' => (int) $row->owner_id,
                'name' => $row->owner_name,
                'email' => $row->owner_email,
            ] : null,
            'plan' => $row->plan_id !== null ? [
                'id' => $row->plan_id,
                'name' => $row->plan_name,
                'slug' => $row->plan_slug,
            ] : null,
            'subscription' => $subscription === null ? null : [
                'id' => $subscription->id,
                'status' => $subscription->status,
                'created_at' => $subscription->created_at,
                'current_period_start' => $subscription->current_period_start,
                'current_period_end' => $subscription->current_period_end,
                'cancel_at_period_end' => (bool) $subscription->cancel_at_period_end,
                'provider' => $subscription->stripe_subscription_id !== null ? 'stripe' : null,
                'provider_subscription_id' => self::maskProviderId($subscription->stripe_subscription_id),
            ],
            'metrics' => [
                'users' => (int) $row->users_count,
                'contacts' => (int) $row->contacts_count,
                'conversations' => (int) $row->conversations_count,
                'messages' => (int) $row->messages_count,
                'last_activity_at' => $row->last_activity_at,
            ],
            'usage' => $usage,
            'whatsapp' => [
                'account' => $whatsapp === null ? null : [
                    'id' => $whatsapp->id,
                    'display_name' => $whatsapp->display_name,
                    'status' => $whatsapp->status,
                    'updated_at' => $whatsapp->updated_at,
                ],
                'phones' => $phones,
            ],
            'users' => $members,
            'audit' => [
                'items' => $audit->items(),
                'pagination' => $this->pagination($audit),
            ],
        ];
    }

    private function customerQuery(): Builder
    {
        return DB::table('tenants')
            ->select([
                'tenants.id',
                'tenants.name',
                'tenants.slug',
                'tenants.status',
                'tenants.created_at',
                'tenants.updated_at',
            ])
            ->selectSub($this->ownerQuery()->select('owners.id'), 'owner_id')
            ->selectSub($this->ownerQuery()->select('owners.name'), 'owner_name')
            ->selectSub($this->ownerQuery()->select('owners.email'), 'owner_email')
            ->selectSub($this->countQuery('tenant_users', 'tenant_users.tenant_id', "tenant_users.status = 'active'"), 'users_count')
            ->selectSub($this->countQuery('contacts', 'contacts.tenant_id', 'contacts.deleted_at IS NULL'), 'contacts_count')
            ->selectSub($this->countQuery('conversations', 'conversations.tenant_id', 'conversations.deleted_at IS NULL'), 'conversations_count')
            ->selectSub($this->countQuery('messages', 'messages.tenant_id'), 'messages_count')
            ->selectSub($this->latestActivityQuery(), 'last_activity_at')
            ->selectSub($this->subscriptionQuery()->select('subscriptions.plan_id'), 'plan_id')
            ->selectSub($this->subscriptionQuery()->join('plans', 'plans.id', '=', 'subscriptions.plan_id')->select('plans.name'), 'plan_name')
            ->selectSub($this->subscriptionQuery()->join('plans', 'plans.id', '=', 'subscriptions.plan_id')->select('plans.slug'), 'plan_slug')
            ->selectSub($this->subscriptionQuery()->select('subscriptions.status'), 'subscription_status')
            ->selectSub(DB::table('whatsapp_accounts')->whereColumn('whatsapp_accounts.tenant_id', 'tenants.id')->select('status')->limit(1), 'whatsapp_status');
    }

    private function ownerQuery(): Builder
    {
        return DB::table('tenant_users as owner_memberships')
            ->join('users as owners', 'owners.id', '=', 'owner_memberships.user_id')
            ->whereColumn('owner_memberships.tenant_id', 'tenants.id')
            ->where('owner_memberships.role', 'owner')
            ->where('owner_memberships.status', TenantMembershipStatus::Active->value)
            ->orderBy('owners.id')
            ->limit(1);
    }

    private function subscriptionQuery(): Builder
    {
        return DB::table('subscriptions')
            ->whereColumn('subscriptions.tenant_id', 'tenants.id')
            ->whereNull('subscriptions.deleted_at')
            ->latest('subscriptions.created_at')
            ->latest('subscriptions.id')
            ->limit(1);
    }

    private function countQuery(string $table, string $tenantColumn, string $condition = ''): Builder
    {
        $query = DB::table($table)->whereColumn($tenantColumn, 'tenants.id');

        if ($condition !== '') {
            $query->whereRaw($condition);
        }

        return $query->selectRaw('COUNT(*)');
    }

    private function latestActivityQuery(): Builder
    {
        return DB::table('conversations')
            ->whereColumn('conversations.tenant_id', 'tenants.id')
            ->whereNull('conversations.deleted_at')
            ->selectRaw('MAX(conversations.updated_at)');
    }

    /** @param array<string, mixed> $filters */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (($filters['search'] ?? '') !== '') {
            $search = self::escapeLike((string) $filters['search']);
            $query->where(function (Builder $nested) use ($search): void {
                $nested->whereRaw("LOWER(tenants.name) LIKE LOWER(?) ESCAPE '!'", ["%{$search}%"])
                    ->orWhereExists($this->ownerQuery()->where(function (Builder $owner) use ($search): void {
                        $owner->whereRaw("LOWER(owners.name) LIKE LOWER(?) ESCAPE '!'", ["%{$search}%"])
                            ->orWhereRaw("LOWER(owners.email) LIKE LOWER(?) ESCAPE '!'", ["%{$search}%"]);
                    }));
            });
        }

        if (($filters['plan_id'] ?? '') !== '') {
            $query->whereExists($this->subscriptionQuery()->where('subscriptions.plan_id', $filters['plan_id']));
        }

        if (($filters['subscription_status'] ?? '') !== '') {
            $query->whereExists($this->subscriptionQuery()->where('subscriptions.status', $filters['subscription_status']));
        }

        if (($filters['status'] ?? '') !== '') {
            $query->where('tenants.status', $filters['status']);
        }

        if (($filters['created_from'] ?? '') !== '') {
            $query->whereDate('tenants.created_at', '>=', $filters['created_from']);
        }

        if (($filters['created_to'] ?? '') !== '') {
            $query->whereDate('tenants.created_at', '<=', $filters['created_to']);
        }
    }

    /** @return array<string, mixed> */
    private function summary(object $row): array
    {
        return [
            'id' => $row->id,
            'name' => $row->name,
            'slug' => $row->slug,
            'status' => $row->status,
            'created_at' => $row->created_at,
            'owner' => $row->owner_id !== null ? [
                'name' => $row->owner_name,
                'email' => $row->owner_email,
            ] : null,
            'plan' => $row->plan_id !== null ? [
                'id' => $row->plan_id,
                'name' => $row->plan_name,
                'slug' => $row->plan_slug,
            ] : null,
            'subscription_status' => $row->subscription_status,
            'users_count' => (int) $row->users_count,
            'whatsapp_status' => $row->whatsapp_status,
            'usage' => null,
            'metrics' => [
                'contacts' => (int) $row->contacts_count,
                'conversations' => (int) $row->conversations_count,
                'messages' => (int) $row->messages_count,
                'last_activity_at' => $row->last_activity_at,
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function usage(string $tenantId): ?array
    {
        $tenant = Tenant::query()->find($tenantId);

        if ($tenant === null) {
            return null;
        }

        try {
            $summary = $this->usageService->currentPeriodSummary($tenant);
        } catch (SubscriptionNotFoundException) {
            return null;
        }

        $categories = [];
        foreach ($summary->categories as $category => $values) {
            $categories[$category] = [
                'used' => $values->used,
                'limit' => $values->limit,
                'remaining' => $values->remaining,
                'percentage' => $values->limit === null || $values->limit === 0
                    ? null
                    : min(100, round(($values->used / $values->limit) * 100, 1)),
            ];
        }

        return [
            'period_start' => $summary->periodStart,
            'period_end' => $summary->periodEnd,
            'categories' => $categories,
        ];
    }

    /**
     * @param  LengthAwarePaginator<int, object>  $paginator
     * @return array<string, int>
     */
    private function pagination(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    private static function maskPhone(?string $phone): ?string
    {
        if ($phone === null || strlen($phone) < 4) {
            return $phone;
        }

        return str_repeat('*', max(0, strlen($phone) - 4)).substr($phone, -4);
    }

    private static function maskProviderId(?string $id): ?string
    {
        if ($id === null || strlen($id) <= 8) {
            return $id;
        }

        return substr($id, 0, 4).'...'.substr($id, -4);
    }
}
