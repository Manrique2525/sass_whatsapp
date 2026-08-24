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
| Billing API Permission Tests (FASE 23 U3)
|--------------------------------------------------------------------------
|
| BILL-API-PERM-01..10 — Owner vs admin vs agent access matrix.
| Corren en SQLite :memory:.
|
*/

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->owner = User::factory()->create();
    $this->admin = User::factory()->create();
    $this->agent = User::factory()->create();
    $this->plan = Plan::factory()->create();

    make_tenant_member($this->owner, $this->tenant, 'owner');
    make_tenant_member($this->admin, $this->tenant, 'admin');
    make_tenant_member($this->agent, $this->tenant, 'agent');

    TenantContext::setId($this->tenant->id);
});

it('BILL-API-PERM-01: owner can list plans', function (): void {
    $response = $this->actingAs($this->owner)->getJson(
        '/api/v1/tenants/'.$this->tenant->id.'/plans',
    );
    $response->assertOk();
})->group('BILL-API-PERM-01');

it('BILL-API-PERM-02: admin can list plans', function (): void {
    $response = $this->actingAs($this->admin)->getJson(
        '/api/v1/tenants/'.$this->tenant->id.'/plans',
    );
    $response->assertOk();
})->group('BILL-API-PERM-02');

it('BILL-API-PERM-03: agent cannot list plans (403)', function (): void {
    $response = $this->actingAs($this->agent)->getJson(
        '/api/v1/tenants/'.$this->tenant->id.'/plans',
    );
    $response->assertForbidden()->assertJson([
        'code' => 'PERMISSION_DENIED',
    ]);
})->group('BILL-API-PERM-03');

it('BILL-API-PERM-04: owner can manage subscription', function (): void {
    $response = $this->actingAs($this->owner)->postJson(
        '/api/v1/tenants/'.$this->tenant->id.'/subscriptions',
        ['plan_id' => $this->plan->id],
    );
    $response->assertCreated();
})->group('BILL-API-PERM-04');

it('BILL-API-PERM-05: admin cannot manage subscription (403)', function (): void {
    $response = $this->actingAs($this->admin)->postJson(
        '/api/v1/tenants/'.$this->tenant->id.'/subscriptions',
        ['plan_id' => $this->plan->id],
    );
    $response->assertForbidden()->assertJson([
        'code' => 'PERMISSION_DENIED',
    ]);
})->group('BILL-API-PERM-05');

it('BILL-API-PERM-06: agent cannot manage subscription (403)', function (): void {
    $response = $this->actingAs($this->agent)->postJson(
        '/api/v1/tenants/'.$this->tenant->id.'/subscriptions',
        ['plan_id' => $this->plan->id],
    );
    $response->assertForbidden()->assertJson([
        'code' => 'PERMISSION_DENIED',
    ]);
})->group('BILL-API-PERM-06');

it('BILL-API-PERM-07: owner can view usage', function (): void {
    Subscription::factory()->create([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => 'active',
    ]);

    $response = $this->actingAs($this->owner)->getJson(
        '/api/v1/tenants/'.$this->tenant->id.'/usage',
    );
    $response->assertOk();
})->group('BILL-API-PERM-07');

it('BILL-API-PERM-08: admin can view usage', function (): void {
    Subscription::factory()->create([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => 'active',
    ]);

    $response = $this->actingAs($this->admin)->getJson(
        '/api/v1/tenants/'.$this->tenant->id.'/usage',
    );
    $response->assertOk();
})->group('BILL-API-PERM-08');

it('BILL-API-PERM-09: agent cannot view usage (403)', function (): void {
    Subscription::factory()->create([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => 'active',
    ]);

    $response = $this->actingAs($this->agent)->getJson(
        '/api/v1/tenants/'.$this->tenant->id.'/usage',
    );
    $response->assertForbidden()->assertJson([
        'code' => 'PERMISSION_DENIED',
    ]);
})->group('BILL-API-PERM-09');

it('BILL-API-PERM-10: non-member user returns 403', function (): void {
    $stranger = User::factory()->create();

    $response = $this->actingAs($stranger)->getJson(
        '/api/v1/tenants/'.$this->tenant->id.'/plans',
    );
    $response->assertForbidden();
})->group('BILL-API-PERM-10');
