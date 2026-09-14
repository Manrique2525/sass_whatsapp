<?php

declare(strict_types=1);

use App\Application\Users\Services\TenantRoleManager;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Billing\Models\Plan;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function platform_plan_admin(): User
{
    test()->seed(RolesAndPermissionsSeeder::class);
    $user = User::factory()->create();
    app(TenantRoleManager::class)->assignGlobalRole($user, UserRole::SuperAdmin);

    return $user->fresh();
}

function valid_platform_plan(string $slug = 'starter'): array
{
    return [
        'slug' => $slug, 'name' => 'Starter', 'description' => 'Starter plan', 'is_active' => true,
        'price_monthly' => '12.00', 'price_yearly' => '120.00', 'stripe_price_id_monthly' => null,
        'stripe_price_id_yearly' => null, 'limits' => ['messages' => 100, 'ai_tokens' => 1000, 'contacts' => 50, 'flow_executions' => 10, 'users' => 3, 'knowledge_documents' => 2],
        'features' => ['ai_enabled' => false], 'sort_order' => 1,
    ];
}

test('platform plan catalog enforces its authorization matrix', function (): void {
    expect($this->get('/platform/plans')->status())->toBe(302);

    foreach (['owner', 'admin', 'agent'] as $role) {
        $user = User::factory()->create();
        make_tenant_member($user, Tenant::factory()->create(), $role);
        $this->actingAs($user)->get('/platform/plans')->assertForbidden();
    }

    $this->actingAs(platform_plan_admin())->get('/platform/plans')->assertOk();
});

test('tenant roles are denied on every plan management endpoint', function (): void {
    $plan = Plan::factory()->create();
    $payload = valid_platform_plan('new-plan');

    foreach (['owner', 'admin', 'agent'] as $role) {
        $user = User::factory()->create();
        make_tenant_member($user, Tenant::factory()->create(), $role);

        $this->actingAs($user)->get('/platform/plans/create')->assertForbidden();
        $this->actingAs($user)->post('/platform/plans', $payload)->assertForbidden();
        $this->actingAs($user)->get('/platform/plans/'.$plan->id)->assertForbidden();
        $this->actingAs($user)->get('/platform/plans/'.$plan->id.'/edit')->assertForbidden();
        $this->actingAs($user)->patch('/platform/plans/'.$plan->id, $payload)->assertForbidden();
    }
});

test('platform admin can create and update a plan with global audit and impact data', function (): void {
    $this->seed(PlanSeeder::class);
    $admin = platform_plan_admin();
    TenantContext::set(Tenant::factory()->create());

    $response = $this->actingAs($admin)->post('/platform/plans', valid_platform_plan());
    $plan = Plan::query()->where('slug', 'starter')->firstOrFail();
    $response->assertRedirect('/platform/plans/'.$plan->id);

    $this->actingAs($admin)->patch('/platform/plans/'.$plan->id, [...valid_platform_plan(), 'name' => 'Starter Plus', 'features' => ['ai_enabled' => true], 'reason' => 'Enable AI for the new commercial tier'])
        ->assertRedirect('/platform/plans/'.$plan->id);

    $this->actingAs($admin)->get('/platform/plans/'.$plan->id)->assertInertia(fn ($page) => $page
        ->where('plan.name', 'Starter Plus')->where('plan.features.ai_enabled', true)
        ->where('plan.impact.active_subscriptions', 0)->where('plan.impact.past_due_subscriptions', 0));

    expect(AuditLog::query()->whereNull('tenant_id')->where('subject_id', $plan->id)->pluck('action')->all())
        ->toContain('platform.plan.created', 'platform.plan.updated', 'platform.plan.features_changed');
});

test('free plan cannot be deactivated or repriced and duplicate slugs are rejected', function (): void {
    $this->seed(PlanSeeder::class);
    $admin = platform_plan_admin();
    $free = Plan::query()->where('slug', 'free')->firstOrFail();

    $this->actingAs($admin)->patch('/platform/plans/'.$free->id, [...valid_platform_plan('free'), 'name' => 'Changed', 'is_active' => false, 'price_monthly' => '1.00'])->assertSessionHasErrors('slug');
    $this->actingAs($admin)->post('/platform/plans', valid_platform_plan('free'))->assertSessionHasErrors('slug');

    expect($free->fresh()->is_active)->toBeTrue()->and((string) $free->fresh()->price_monthly)->toBe('0.00');
});

test('plan catalog does not mutate subscriptions or usage data', function (): void {
    $this->seed(PlanSeeder::class);
    $admin = platform_plan_admin();
    $plan = Plan::query()->where('slug', 'free')->firstOrFail();
    $tenant = Tenant::factory()->create();
    DB::table('subscriptions')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'status' => 'active', 'quantity' => 1, 'cancel_at_period_end' => false, 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($admin)->get('/platform/plans/'.$plan->id)->assertInertia(fn ($page) => $page->where('plan.impact.active_subscriptions', 1));
    expect(DB::table('subscriptions')->where('plan_id', $plan->id)->count())->toBe(1);
});

test('sensitive plan changes require a reason and linked plans cannot be repriced', function (): void {
    $admin = platform_plan_admin();
    $plan = Plan::factory()->create(['slug' => 'pro', 'price_monthly' => 20]);
    $tenant = Tenant::factory()->create();

    DB::table('subscriptions')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => 'past_due',
        'quantity' => 1,
        'cancel_at_period_end' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($admin)
        ->patch('/platform/plans/'.$plan->id, [...valid_platform_plan('pro'), 'price_monthly' => '25.00'])
        ->assertSessionHasErrors('reason');

    $this->actingAs($admin)
        ->patch('/platform/plans/'.$plan->id, [...valid_platform_plan('pro'), 'price_monthly' => '25.00', 'reason' => 'New pricing version'])
        ->assertSessionHasErrors('price_monthly');

    expect((string) $plan->fresh()->price_monthly)->toBe('20.00');
});
