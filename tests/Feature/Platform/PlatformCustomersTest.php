<?php

declare(strict_types=1);

use App\Application\Users\Services\TenantRoleManager;
use App\Domain\Billing\Models\Plan;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function u3_platform_admin(): User
{
    test()->seed(RolesAndPermissionsSeeder::class);

    $user = User::factory()->create();
    app(TenantRoleManager::class)->assignGlobalRole($user, UserRole::SuperAdmin);

    return authenticated_platform_admin($user);
}

function u3_customer(string $name, string $ownerEmail, string $planId, string $subscriptionStatus = 'active'): Tenant
{
    $tenant = Tenant::factory()->create(['name' => $name]);
    $owner = User::factory()->create(['email' => $ownerEmail]);
    make_tenant_member($owner, $tenant, 'owner');

    DB::table('subscriptions')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => $tenant->id,
        'plan_id' => $planId,
        'status' => $subscriptionStatus,
        'quantity' => 1,
        'metadata' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $tenant;
}

test('platform customer index is paginated, searchable, filtered, and bounded', function (): void {
    $plan = Plan::factory()->create(['name' => 'Pro']);
    u3_customer('Acme Support', 'owner@acme.test', $plan->id);
    u3_customer('Beta Retail', 'owner@beta.test', $plan->id, 'past_due');
    $admin = u3_platform_admin();

    DB::enableQueryLog();
    $response = $this->actingAs($admin)->get('/platform/customers?search=acme&plan_id='.$plan->id.'&per_page=10');

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->component('Platform/Customers/Index')
        ->where('pagination.total', 1)
        ->where('customers.0.name', 'Acme Support')
        ->where('customers.0.owner.email', 'owner@acme.test')
        ->where('customers.0.subscription_status', 'active'));

    // Auth/shared props, MFA boundary, and the bounded list query; tenant count must not add N+1 queries.
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(6);
    DB::disableQueryLog();
});

test('platform customer detail resolves safe owner, subscription, usage, and WhatsApp data', function (): void {
    $plan = Plan::factory()->create([
        'name' => 'Pro',
        'limits' => ['messages' => 100, 'contacts' => 10, 'users' => 5],
    ]);
    $tenant = u3_customer('Acme Support', 'owner@acme.test', $plan->id);
    $admin = u3_platform_admin();

    $subscriptionId = (string) DB::table('subscriptions')->where('tenant_id', $tenant->id)->value('id');
    $contactId = (string) Str::uuid();
    $conversationId = (string) Str::uuid();
    $accountId = (string) Str::uuid();

    DB::table('contacts')->insert([
        'id' => $contactId,
        'tenant_id' => $tenant->id,
        'phone' => '+15550000001',
        'name' => 'Customer',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('conversations')->insert([
        'id' => $conversationId,
        'tenant_id' => $tenant->id,
        'contact_id' => $contactId,
        'status' => 'open',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('messages')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => $tenant->id,
        'conversation_id' => $conversationId,
        'direction' => 'inbound',
        'type' => 'text',
        'status' => 'delivered',
        'body' => 'private message content',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('usage_records')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => $tenant->id,
        'subscription_id' => $subscriptionId,
        'category' => 'messages',
        'quantity' => 3,
        'metadata' => '{}',
        'recorded_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('whatsapp_accounts')->insert([
        'id' => $accountId,
        'tenant_id' => $tenant->id,
        'display_name' => 'Acme WhatsApp',
        'status' => 'connected',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('whatsapp_phone_numbers')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => $tenant->id,
        'whatsapp_account_id' => $accountId,
        'phone_id' => 'meta-phone-id',
        'display_phone_number' => '+15550000001',
        'verified_name' => 'Acme',
        'status' => 'connected',
        'is_default' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('audit_logs')->insert([
        'id' => (string) Str::uuid(),
        'actor_user_id' => $admin->id,
        'tenant_id' => $tenant->id,
        'action' => 'tenant.updated',
        'subject_type' => Tenant::class,
        'subject_id' => $tenant->id,
        'data' => json_encode(['private' => 'not exposed'], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($admin)->get('/platform/customers/'.$tenant->id);

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->component('Platform/Customers/Show')
        ->where('customer.name', 'Acme Support')
        ->where('customer.owner.email', 'owner@acme.test')
        ->where('customer.metrics.messages', 1)
        ->where('customer.usage.categories.messages.used', 3)
        ->where('customer.usage.categories.messages.limit', 100)
        ->where('customer.whatsapp.account.status', 'connected')
        ->where('customer.whatsapp.phones.0.display_phone_number', '********0001')
        ->where('customer.audit.items.0.action', 'tenant.updated')
        ->missing('customer.audit.items.0.data'));

    expect($response->getContent())
        ->not->toContain('private message content')
        ->not->toContain('not exposed')
        ->not->toContain('access_token');
});

test('platform customer detail is available without tenant membership and unknown tenant is not found', function (): void {
    $admin = u3_platform_admin();
    $tenant = Tenant::factory()->create(['name' => 'No Membership Customer']);

    $this->actingAs($admin)->get('/platform/customers/'.$tenant->id)->assertOk();
    $this->actingAs($admin)->get('/platform/customers/'.Str::uuid())->assertNotFound();
});

test('platform customer reads ignore stale tenant context', function (): void {
    $plan = Plan::factory()->create();
    $tenantA = u3_customer('Tenant A', 'owner-a@acme.test', $plan->id);
    $tenantB = u3_customer('Tenant B', 'owner-b@acme.test', $plan->id);
    $admin = u3_platform_admin();

    TenantContext::set($tenantA);

    try {
        $this->actingAs($admin)
            ->get('/platform/customers?search=Tenant B')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pagination.total', 1)
                ->where('customers.0.id', $tenantB->id));

        $this->actingAs($admin)->get('/platform/customers/'.$tenantB->id)->assertOk();
    } finally {
        TenantContext::clear();
    }
});

test('platform admin creates a Free customer with an owner and sends activation mail', function (): void {
    Notification::fake();
    $free = Plan::factory()->create(['slug' => 'free', 'name' => 'Free']);
    $admin = u3_platform_admin();

    $response = $this->actingAs($admin)->post('/platform/customers', [
        'tenant_name' => 'Demo Barbería',
        'owner_name' => 'Demo Owner',
        'owner_email' => 'owner.demo@local.test',
    ]);

    $response->assertRedirect();
    $owner = User::query()->where('email', 'owner.demo@local.test')->firstOrFail();
    $tenant = Tenant::query()->where('name', 'Demo Barbería')->firstOrFail();

    expect($tenant->tenantUsers()->where('user_id', $owner->id)->where('role', UserRole::Owner)->exists())->toBeTrue()
        ->and(DB::table('subscriptions')->where('tenant_id', $tenant->id)->where('plan_id', $free->id)->where('status', 'active')->exists())->toBeTrue()
        ->and($admin->tenantUsers()->where('tenant_id', $tenant->id)->exists())->toBeFalse()
        ->and($owner->email_verified_at)->toBeNull();

    Notification::assertSentTo($owner, VerifyEmail::class);
    expect(DB::table('audit_logs')->where('action', 'platform.customer.created')->where('actor_user_id', $admin->id)->exists())->toBeTrue();
});

test('platform admin requires explicit confirmation before attaching an existing owner', function (): void {
    Plan::factory()->create(['slug' => 'free', 'name' => 'Free']);
    $admin = u3_platform_admin();
    $owner = User::factory()->create(['email' => 'existing-owner@local.test']);

    $this->actingAs($admin)->post('/platform/customers', [
        'tenant_name' => 'Existing Owner Tenant',
        'owner_name' => 'Existing Owner',
        'owner_email' => $owner->email,
    ])->assertSessionHasErrors('owner_email');

    expect(Tenant::query()->where('name', 'Existing Owner Tenant')->exists())->toBeFalse();

    $this->actingAs($admin)->post('/platform/customers', [
        'tenant_name' => 'Existing Owner Tenant',
        'owner_name' => 'Existing Owner',
        'owner_email' => $owner->email,
        'confirm_existing_owner' => true,
    ])->assertRedirect();

    expect($owner->fresh()->tenantUsers()->where('role', UserRole::Owner)->count())->toBe(1)
        ->and(User::query()->where('email', $owner->email)->count())->toBe(1);
});

test('platform admin can suspend and reactivate a tenant without deleting data', function (): void {
    $admin = u3_platform_admin();
    $tenant = Tenant::factory()->create(['status' => 'active']);

    $this->actingAs($admin)->post('/platform/customers/'.$tenant->id.'/suspend', [
        'confirm' => true,
        'reason' => 'Customer requested a temporary pause',
    ])->assertRedirect();
    expect($tenant->fresh()->status->value)->toBe('suspended');

    $this->actingAs($admin)->post('/platform/customers/'.$tenant->id.'/reactivate', [
        'confirm' => true,
        'reason' => 'Customer is ready to resume',
    ])->assertRedirect();
    expect($tenant->fresh()->status->value)->toBe('active')
        ->and(DB::table('audit_logs')->whereIn('action', ['platform.tenant.suspended', 'platform.tenant.reactivated'])->count())->toBe(2);
});

test('platform admin can repair a missing subscription with the canonical Free plan', function (): void {
    $free = Plan::factory()->create(['slug' => 'free', 'name' => 'Free']);
    $admin = u3_platform_admin();
    $tenant = Tenant::factory()->create(['plan_id' => null]);

    $this->actingAs($admin)->post('/platform/customers/'.$tenant->id.'/subscription/free', ['confirm' => true])->assertRedirect();

    expect(DB::table('subscriptions')->where('tenant_id', $tenant->id)->where('plan_id', $free->id)->where('status', 'active')->exists())->toBeTrue();

    $this->actingAs($admin)->post('/platform/customers/'.$tenant->id.'/subscription/free', ['confirm' => true])->assertRedirect();
    expect(DB::table('subscriptions')->where('tenant_id', $tenant->id)->whereNull('deleted_at')->count())->toBe(1);
});
