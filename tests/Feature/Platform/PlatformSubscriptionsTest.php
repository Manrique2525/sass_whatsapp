<?php

declare(strict_types=1);

use App\Application\Users\Services\TenantRoleManager;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Models\UsageRecord;
use App\Domain\Billing\Models\UsageReservation;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
});

function u5_platform_admin(): User
{
    test()->seed(RolesAndPermissionsSeeder::class);
    $user = User::factory()->create();
    app(TenantRoleManager::class)->assignGlobalRole($user, UserRole::SuperAdmin);

    return authenticated_platform_admin($user);
}

function u5_subscription_fixture(bool $providerManaged = false): array
{
    $current = Plan::factory()->create([
        'name' => 'Current',
        'slug' => 'current-'.Str::lower(Str::random(6)),
        'limits' => ['messages' => 100, 'contacts' => 10, 'users' => 5],
        'features' => ['ai_enabled' => true],
    ]);
    $target = Plan::factory()->create([
        'name' => 'Target',
        'slug' => 'target-'.Str::lower(Str::random(6)),
        'limits' => ['messages' => 5, 'contacts' => 3, 'users' => 2],
        'features' => ['ai_enabled' => false],
    ]);
    $tenant = Tenant::factory()->create(['plan_id' => $current->id]);
    TenantContext::setId($tenant->id);
    $subscription = Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $current->id,
        'status' => SubscriptionStatus::Active,
        'stripe_subscription_id' => $providerManaged ? 'sub_provider_123456' : null,
    ]);

    return [$tenant, $subscription, $current, $target];
}

test('platform subscriptions index and detail are paginated and read usage without side effects', function (): void {
    [$tenant, $subscription, $current] = u5_subscription_fixture();
    $admin = u5_platform_admin();
    UsageRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'subscription_id' => $subscription->id,
        'category' => 'messages',
        'quantity' => 4,
    ]);

    $this->actingAs($admin)->get('/platform/subscriptions?plan_id='.$current->id.'&provider=local&per_page=10')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Platform/Subscriptions/Index')
            ->where('pagination.total', 1)
            ->where('subscriptions.0.tenant.id', $tenant->id)
            ->where('subscriptions.0.usage.categories.messages.used', 4));

    $beforeRecords = UsageRecord::count();
    $beforeReservations = UsageReservation::count();
    $this->actingAs($admin)->get('/platform/subscriptions/'.$subscription->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Platform/Subscriptions/Show')
            ->where('subscription.plan.id', $current->id)
            ->where('usage.categories.messages.used', 4));

    expect(UsageRecord::count())->toBe($beforeRecords)
        ->and(UsageReservation::count())->toBe($beforeReservations);
});

test('platform admin changes local plan through canonical service with reason, impact safety, and global audit', function (): void {
    [$tenant, $subscription, $current, $target] = u5_subscription_fixture();
    $admin = u5_platform_admin();
    UsageRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'subscription_id' => $subscription->id,
        'category' => 'messages',
        'quantity' => 6,
    ]);
    $beforeUsage = UsageRecord::query()->where('subscription_id', $subscription->id)->sum('quantity');

    $this->actingAs($admin)->patch('/platform/customers/'.$tenant->id.'/subscription/plan', [
        'plan_id' => $target->id,
        'reason' => 'Customer requested a controlled downgrade',
        'current_password' => 'password',
    ])->assertRedirect('/platform/customers/'.$tenant->id);

    expect($subscription->fresh()->plan_id)->toBe($target->id)
        ->and($tenant->fresh()->plan_id)->toBe($target->id)
        ->and(UsageRecord::query()->where('subscription_id', $subscription->id)->sum('quantity'))->toBe($beforeUsage);

    $audit = AuditLog::query()->whereNull('tenant_id')->where('action', 'platform.subscription.plan_changed')->latest('id')->firstOrFail();
    expect($audit->subject_id)->toBe($subscription->id)
        ->and($audit->data['reason'])->toBe('Customer requested a controlled downgrade')
        ->and($audit->data['from_plan']['id'])->toBe($current->id)
        ->and($audit->data['to_plan']['id'])->toBe($target->id);

    $this->actingAs($admin)->get('/platform/customers/'.$tenant->id)->assertInertia(fn ($page) => $page
        ->where('customer.audit.items.0.action', 'platform.subscription.plan_changed'));
});

test('platform plan changes reject same, inactive, missing reason, and provider-managed subscriptions', function (): void {
    [$tenant, $subscription, , $target] = u5_subscription_fixture();
    $admin = u5_platform_admin();

    $this->actingAs($admin)->patch('/platform/customers/'.$tenant->id.'/subscription/plan', ['plan_id' => $subscription->plan_id, 'reason' => 'No-op', 'current_password' => 'password'])->assertSessionHasErrors('plan_id');
    $this->actingAs($admin)->patch('/platform/customers/'.$tenant->id.'/subscription/plan', ['plan_id' => $target->id, 'current_password' => 'password'])->assertSessionHasErrors('reason');

    $inactive = Plan::factory()->inactive()->create();
    $this->actingAs($admin)->patch('/platform/customers/'.$tenant->id.'/subscription/plan', ['plan_id' => $inactive->id, 'reason' => 'Try inactive', 'current_password' => 'password'])->assertSessionHasErrors('plan_id');

    $subscription->update(['stripe_subscription_id' => 'sub_provider_123456']);
    $this->actingAs($admin)->patch('/platform/customers/'.$tenant->id.'/subscription/plan', ['plan_id' => $target->id, 'reason' => 'Try provider managed', 'current_password' => 'password'])->assertSessionHasErrors('plan_id');
    expect($subscription->fresh()->plan_id)->not->toBe($target->id);
});

test('platform subscription operations enforce global authorization and transaction rollback', function (): void {
    [$tenant, $subscription, , $target] = u5_subscription_fixture();
    $this->get('/platform/subscriptions')->assertRedirect();

    foreach (['owner', 'admin', 'agent'] as $role) {
        $user = User::factory()->create();
        make_tenant_member($user, $tenant, $role);
        $this->actingAs($user)->get('/platform/subscriptions')->assertForbidden();
        $this->actingAs($user)->patch('/platform/customers/'.$tenant->id.'/subscription/plan', ['plan_id' => $target->id, 'reason' => 'Unauthorized', 'current_password' => 'password'])->assertForbidden();
    }

    $admin = u5_platform_admin();
    Tenant::updating(function (): void {
        throw new RuntimeException('simulated transactional failure');
    });

    $this->withoutExceptionHandling();
    expect(fn () => $this->actingAs($admin)->patch('/platform/customers/'.$tenant->id.'/subscription/plan', ['plan_id' => $target->id, 'reason' => 'Rollback test', 'current_password' => 'password']))
        ->toThrow(RuntimeException::class);
    expect($subscription->fresh()->plan_id)->not->toBe($target->id)
        ->and($tenant->fresh()->plan_id)->toBe($subscription->plan_id);
});
