<?php

declare(strict_types=1);

use App\Application\Users\Services\TenantRoleManager;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Models\UsageRecord;
use App\Domain\Tenants\Enums\TenantStatus;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function dashboard_platform_admin(): User
{
    test()->seed(RolesAndPermissionsSeeder::class);
    $user = User::factory()->create();
    app(TenantRoleManager::class)->assignGlobalRole($user, UserRole::SuperAdmin);

    return $user->fresh();
}

function dashboard_plan(string $slug, bool $active = true): Plan
{
    return Plan::factory()->create(['slug' => $slug === 'free' ? 'free' : $slug.'-'.Str::lower(Str::random(5)), 'name' => ucfirst($slug), 'is_active' => $active]);
}

test('platform dashboard returns exact global aggregates and safe operations data', function (): void {
    $admin = dashboard_platform_admin();
    $free = dashboard_plan('free');
    $pro = dashboard_plan('pro');
    $tenant = Tenant::factory()->create(['plan_id' => $free->id, 'created_at' => now()->subDays(2)]);
    $secondTenant = Tenant::factory()->create(['plan_id' => $pro->id, 'created_at' => now()->subDays(4)]);
    $owner = User::factory()->create(['name' => 'Tenant Owner']);
    make_tenant_member($owner, $tenant, 'owner');

    TenantContext::setId($tenant->id);
    $subscription = Subscription::factory()->create(['tenant_id' => $tenant->id, 'plan_id' => $free->id, 'status' => SubscriptionStatus::Active]);
    Subscription::factory()->create(['tenant_id' => $secondTenant->id, 'plan_id' => $pro->id, 'status' => SubscriptionStatus::PastDue, 'stripe_subscription_id' => 'sub_dashboard_123']);
    UsageRecord::factory()->create(['tenant_id' => $tenant->id, 'subscription_id' => $subscription->id, 'category' => 'messages', 'quantity' => 7]);
    DB::table('whatsapp_accounts')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'status' => 'connected', 'created_at' => now(), 'updated_at' => now()]);
    AuditLog::create(['actor_user_id' => $admin->id, 'action' => 'platform.plan.updated', 'data' => ['safe' => true]]);

    $this->actingAs($admin)->get('/platform')->assertInertia(fn ($page) => $page
        ->component('Platform/Dashboard')
        ->where('dashboard.summary.total_tenants', 2)
        ->where('dashboard.summary.active_tenants', 2)
        ->where('dashboard.summary.unique_users', 2)
        ->where('dashboard.summary.total_subscriptions', 2)
        ->where('dashboard.summary.active_subscriptions', 1)
        ->where('dashboard.summary.free_tenants', 1)
        ->where('dashboard.subscriptions.statuses.0.status', 'active')
        ->where('dashboard.subscriptions.providers.0.provider', 'local')
        ->where('dashboard.usage.categories.0.category', 'messages')
        ->where('dashboard.usage.categories.0.quantity', 7)
        ->where('dashboard.whatsapp.connected', 1)
        ->where('dashboard.operations.recent_customers.0.tenant.id', $tenant->id)
        ->where('dashboard.operations.recent_activity.0.action', 'platform.plan.updated'));
});

test('dashboard excludes deleted subscriptions and works with no tenant context', function (): void {
    $admin = dashboard_platform_admin();
    $plan = dashboard_plan('starter');
    $tenant = Tenant::factory()->create(['status' => TenantStatus::Suspended, 'plan_id' => $plan->id]);
    TenantContext::setId($tenant->id);
    $subscription = Subscription::factory()->create(['tenant_id' => $tenant->id, 'plan_id' => $plan->id]);
    $subscription->delete();
    TenantContext::clear();

    $this->actingAs($admin)->get('/platform/dashboard')->assertInertia(fn ($page) => $page
        ->where('dashboard.summary.total_tenants', 1)
        ->where('dashboard.summary.active_tenants', 0)
        ->where('dashboard.summary.total_subscriptions', 0)
        ->where('dashboard.summary.active_subscriptions', 0)
        ->where('dashboard.operations.recent_customers.0.tenant.id', $tenant->id));

    expect(TenantContext::bound())->toBeFalse();
});

test('dashboard access is restricted to super admins', function (): void {
    $tenant = Tenant::factory()->create();

    $this->get('/platform')->assertRedirect('/login');
    foreach (['owner', 'admin', 'agent'] as $role) {
        $user = User::factory()->create();
        make_tenant_member($user, $tenant, $role);
        $this->actingAs($user)->get('/platform')->assertForbidden();
    }
});

test('new customer trend has six deterministic periods including empty months', function (): void {
    $admin = dashboard_platform_admin();
    Tenant::factory()->create(['created_at' => Carbon::now()->subMonths(2)->startOfMonth()->addDay()]);

    $this->actingAs($admin)->get('/platform')->assertInertia(fn ($page) => $page
        ->has('dashboard.trends.new_customers', 6)
        ->where('dashboard.trends.new_customers.0.count', 0));
});
