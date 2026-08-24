<?php

declare(strict_types=1);

use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
});

/*
|--------------------------------------------------------------------------
| Billing API Multi-Tenancy Tests (FASE 23 U3)
|--------------------------------------------------------------------------
|
| BILL-API-MT-U3-01..05 — Cross-tenant isolation for billing endpoints.
| Corren en SQLite :memory:.
|
*/

beforeEach(function (): void {
    $this->tenantA = Tenant::factory()->create();
    $this->tenantB = Tenant::factory()->create();
    $this->userA = User::factory()->create();
    $this->userB = User::factory()->create();
    $this->planA = Plan::factory()->create();

    make_tenant_member($this->userA, $this->tenantA, 'owner');
    make_tenant_member($this->userB, $this->tenantB, 'owner');

    TenantContext::setId($this->tenantA->id);

    Subscription::factory()->create([
        'tenant_id' => $this->tenantA->id,
        'plan_id' => $this->planA->id,
        'status' => 'active',
    ]);
});

it('BILL-API-MT-U3-01: user A cannot read subscription of tenant B', function (): void {
    $response = $this->actingAs($this->userA)->getJson(
        '/api/v1/tenants/'.$this->tenantB->id.'/subscriptions',
    );

    $response->assertNotFound();
})->group('BILL-API-MT-U3-01');

it('BILL-API-MT-U3-02: user B cannot read subscription of tenant A', function (): void {
    $response = $this->actingAs($this->userB)->getJson(
        '/api/v1/tenants/'.$this->tenantA->id.'/subscriptions',
    );

    $response->assertNotFound();
})->group('BILL-API-MT-U3-02');

it('BILL-API-MT-U3-03: user A cannot assign plan to tenant B', function (): void {
    $response = $this->actingAs($this->userA)->postJson(
        '/api/v1/tenants/'.$this->tenantB->id.'/subscriptions',
        ['plan_id' => $this->planA->id],
    );

    $response->assertNotFound();
})->group('BILL-API-MT-U3-03');

it('BILL-API-MT-U3-04: user A cannot read usage of tenant B', function (): void {
    $response = $this->actingAs($this->userA)->getJson(
        '/api/v1/tenants/'.$this->tenantB->id.'/usage',
    );

    $response->assertNotFound();
})->group('BILL-API-MT-U3-04');

it('BILL-API-MT-U3-05: user A cannot read plan catalog of tenant B', function (): void {
    $response = $this->actingAs($this->userA)->getJson(
        '/api/v1/tenants/'.$this->tenantB->id.'/plans',
    );

    $response->assertNotFound();
})->group('BILL-API-MT-U3-05');
