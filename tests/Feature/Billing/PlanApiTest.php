<?php

declare(strict_types=1);

use App\Domain\Billing\Models\Plan;
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
| Plan API Tests (FASE 23 U3)
|--------------------------------------------------------------------------
|
| BILL-API-PLAN-01..05 — Plan catalog read API.
| Corren en SQLite :memory:.
|
*/

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->owner = User::factory()->create();
    make_tenant_member($this->owner, $this->tenant, 'owner');
    TenantContext::setId($this->tenant->id);
});

it('BILL-API-PLAN-01: list returns active plans', function (): void {
    Plan::factory()->count(3)->create(['is_active' => true]);
    Plan::factory()->create(['is_active' => false]);

    $response = $this->actingAs($this->owner)->getJson(
        '/api/v1/tenants/'.$this->tenant->id.'/plans',
    );

    $response->assertOk()->assertJsonStructure([
        'plans' => [['id', 'slug', 'name', 'price_monthly', 'price_yearly', 'limits', 'features']],
    ]);

    $response->assertJsonPath('plans', fn (array $plans) => count($plans) === 3);
})->group('BILL-API-PLAN-01');

it('BILL-API-PLAN-02: show returns plan details', function (): void {
    $plan = Plan::factory()->create();

    $response = $this->actingAs($this->owner)->getJson(
        '/api/v1/tenants/'.$this->tenant->id.'/plans/'.$plan->id,
    );

    $response->assertOk()->assertJson([
        'plan' => [
            'id' => $plan->id,
            'slug' => $plan->slug,
            'name' => $plan->name,
        ],
    ]);
})->group('BILL-API-PLAN-02');

it('BILL-API-PLAN-03: show nonexistent plan returns 404', function (): void {
    $fakeId = (string) Str::uuid();

    $response = $this->actingAs($this->owner)->getJson(
        '/api/v1/tenants/'.$this->tenant->id.'/plans/'.$fakeId,
    );

    $response->assertNotFound();
})->group('BILL-API-PLAN-03');

it('BILL-API-PLAN-04: unauthenticated access returns 401', function (): void {
    $plan = Plan::factory()->create();

    $response = $this->getJson(
        '/api/v1/tenants/'.$this->tenant->id.'/plans/'.$plan->id,
    );

    $response->assertUnauthorized();
})->group('BILL-API-PLAN-04');

it('BILL-API-PLAN-05: agent cannot list plans (403)', function (): void {
    $agent = User::factory()->create();
    make_tenant_member($agent, $this->tenant, 'agent');

    $response = $this->actingAs($agent)->getJson(
        '/api/v1/tenants/'.$this->tenant->id.'/plans',
    );

    $response->assertForbidden()->assertJson([
        'code' => 'PERMISSION_DENIED',
    ]);
})->group('BILL-API-PLAN-05');
