<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
});

/*
|--------------------------------------------------------------------------
| Subscription API Tests (FASE 23 U3)
|--------------------------------------------------------------------------
|
| BILL-API-SUB-01..10 — Subscription CRUD API.
| Corren en SQLite :memory:.
|
*/

function sub_url(Tenant $tenant): string
{
    return '/api/v1/tenants/'.$tenant->id.'/subscriptions';
}

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->owner = User::factory()->create();
    $this->plan = Plan::factory()->create();
    make_tenant_member($this->owner, $this->tenant, 'owner');
    TenantContext::setId($this->tenant->id);
});

it('BILL-API-SUB-01: index returns null when no subscription', function (): void {
    $response = $this->actingAs($this->owner)->getJson(sub_url($this->tenant));

    $response->assertOk()->assertJson([
        'subscription' => null,
    ]);
})->group('BILL-API-SUB-01');

it('BILL-API-SUB-02: index returns active subscription', function (): void {
    Subscription::factory()->create([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => SubscriptionStatus::Active,
    ]);

    $response = $this->actingAs($this->owner)->getJson(sub_url($this->tenant));

    $response->assertOk()->assertJsonStructure([
        'subscription' => ['id', 'status', 'quantity', 'plan'],
    ]);
})->group('BILL-API-SUB-02');

it('BILL-API-SUB-03: store assigns plan and creates subscription', function (): void {
    $response = $this->actingAs($this->owner)->postJson(sub_url($this->tenant), [
        'plan_id' => $this->plan->id,
    ]);

    $response->assertCreated()->assertJson([
        'message' => 'Plan asignado.',
        'subscription' => [
            'status' => 'active',
            'plan' => ['id' => $this->plan->id],
        ],
    ]);

    $this->assertDatabaseHas('subscriptions', [
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => 'active',
    ]);

    $this->tenant->refresh();
    expect($this->tenant->plan_id)->toBe($this->plan->id);
})->group('BILL-API-SUB-03');

it('BILL-API-SUB-04: store with invalid plan_id returns 404', function (): void {
    $fakeId = (string) Str::uuid();

    $response = $this->actingAs($this->owner)->postJson(sub_url($this->tenant), [
        'plan_id' => $fakeId,
    ]);

    $response->assertNotFound();
})->group('BILL-API-SUB-04');

it('BILL-API-SUB-05: store without plan_id returns 422', function (): void {
    $response = $this->actingAs($this->owner)->postJson(sub_url($this->tenant), []);

    $response->assertUnprocessable();
})->group('BILL-API-SUB-05');

it('BILL-API-SUB-06: store replaces existing active subscription', function (): void {
    Subscription::factory()->create([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => SubscriptionStatus::Active,
    ]);

    $newPlan = Plan::factory()->create();

    $response = $this->actingAs($this->owner)->postJson(sub_url($this->tenant), [
        'plan_id' => $newPlan->id,
    ]);

    $response->assertCreated();

    $activeCount = Subscription::withoutGlobalScopes()
        ->where('tenant_id', $this->tenant->id)
        ->where('status', SubscriptionStatus::Active)
        ->count();

    expect($activeCount)->toBe(1);

    $this->assertDatabaseHas('subscriptions', [
        'tenant_id' => $this->tenant->id,
        'plan_id' => $newPlan->id,
        'status' => 'active',
    ]);
})->group('BILL-API-SUB-06');

it('BILL-API-SUB-07: patch changes plan', function (): void {
    Subscription::factory()->create([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => SubscriptionStatus::Active,
    ]);

    $newPlan = Plan::factory()->create();

    $response = $this->actingAs($this->owner)->patchJson(sub_url($this->tenant), [
        'plan_id' => $newPlan->id,
    ]);

    $response->assertOk()->assertJson([
        'message' => 'Plan actualizado.',
        'subscription' => [
            'plan' => ['id' => $newPlan->id],
        ],
    ]);

    $this->tenant->refresh();
    expect($this->tenant->plan_id)->toBe($newPlan->id);
})->group('BILL-API-SUB-07');

it('BILL-API-SUB-08: patch without active subscription returns 404', function (): void {
    $response = $this->actingAs($this->owner)->patchJson(sub_url($this->tenant), [
        'plan_id' => $this->plan->id,
    ]);

    $response->assertNotFound();
})->group('BILL-API-SUB-08');

it('BILL-API-SUB-09: patch same plan is no-op', function (): void {
    Subscription::factory()->create([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => SubscriptionStatus::Active,
    ]);

    $response = $this->actingAs($this->owner)->patchJson(sub_url($this->tenant), [
        'plan_id' => $this->plan->id,
    ]);

    $response->assertOk();
})->group('BILL-API-SUB-09');

it('BILL-API-SUB-10: delete cancels subscription', function (): void {
    Subscription::factory()->create([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => SubscriptionStatus::Active,
    ]);

    $response = $this->actingAs($this->owner)->deleteJson(sub_url($this->tenant));

    $response->assertOk()->assertJson([
        'message' => 'Suscripción cancelada.',
    ]);

    $this->tenant->refresh();
    expect($this->tenant->plan_id)->toBeNull();
})->group('BILL-API-SUB-10');

it('BILL-API-SUB-11: delete without subscription returns 404', function (): void {
    $response = $this->actingAs($this->owner)->deleteJson(sub_url($this->tenant));

    $response->assertNotFound();
})->group('BILL-API-SUB-11');
