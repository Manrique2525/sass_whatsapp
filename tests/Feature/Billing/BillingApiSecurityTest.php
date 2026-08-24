<?php

declare(strict_types=1);

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
| Billing API Security Tests (FASE 23 U3)
|--------------------------------------------------------------------------
|
| BILL-API-SEC-U3-01..06 — Injection, IDOR, input validation.
| Corren en SQLite :memory:.
|
*/

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->owner = User::factory()->create();
    $this->plan = Plan::factory()->create();
    make_tenant_member($this->owner, $this->tenant, 'owner');
    TenantContext::setId($this->tenant->id);
});

it('BILL-API-SEC-U3-01: subscription endpoint rejects non-UUID plan_id', function (): void {
    $response = $this->actingAs($this->owner)->postJson(
        '/api/v1/tenants/'.$this->tenant->id.'/subscriptions',
        ['plan_id' => 'not-a-uuid'],
    );

    $response->assertUnprocessable();
})->group('BILL-API-SEC-U3-01');

it('BILL-API-SEC-U3-02: subscription endpoint rejects empty body', function (): void {
    $response = $this->actingAs($this->owner)->postJson(
        '/api/v1/tenants/'.$this->tenant->id.'/subscriptions',
        [],
    );

    $response->assertUnprocessable();
})->group('BILL-API-SEC-U3-02');

it('BILL-API-SEC-U3-03: plan endpoint rejects invalid tenant UUID', function (): void {
    $response = $this->actingAs($this->owner)->getJson(
        '/api/v1/tenants/'.Str::uuid().'/plans',
    );

    $response->assertNotFound();
})->group('BILL-API-SEC-U3-03');

it('BILL-API-SEC-U3-04: subscription endpoint rejects invalid plan UUID', function (): void {
    $response = $this->actingAs($this->owner)->postJson(
        '/api/v1/tenants/'.$this->tenant->id.'/subscriptions',
        ['plan_id' => (string) Str::uuid()],
    );

    $response->assertNotFound();
})->group('BILL-API-SEC-U3-04');

it('BILL-API-SEC-U3-05: plan response does not expose tenant_id', function (): void {
    $response = $this->actingAs($this->owner)->getJson(
        '/api/v1/tenants/'.$this->tenant->id.'/plans/'.$this->plan->id,
    );

    $response->assertOk();
    $response->assertJsonMissing(['tenant_id']);
})->group('BILL-API-SEC-U3-05');

it('BILL-API-SEC-U3-06: usage response does not expose tenant_id', function (): void {
    $sub = Subscription::factory()->create([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => 'active',
    ]);

    $response = $this->actingAs($this->owner)->getJson(
        '/api/v1/tenants/'.$this->tenant->id.'/usage',
    );

    $response->assertOk();
    $response->assertJsonMissing(['tenant_id']);
})->group('BILL-API-SEC-U3-06');
