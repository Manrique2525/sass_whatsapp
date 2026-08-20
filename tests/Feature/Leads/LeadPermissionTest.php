<?php

declare(strict_types=1);

use App\Domain\Leads\Models\Lead;
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
| Lead Permission Tests (FASE 19 U2)
|--------------------------------------------------------------------------
|
| LEAD-PERM-01..06 — Verificación de permisos leads.view / leads.manage.
| Corren en SQLite :memory:.
|
*/

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    TenantContext::setId($this->tenant->id);
});

it('LEAD-PERM-01: owner can list leads', function (): void {
    $owner = User::factory()->create();
    make_tenant_member($owner, $this->tenant, 'owner');

    $response = $this->actingAs($owner)->getJson('/api/v1/tenants/'.$this->tenant->id.'/leads');

    $response->assertOk();
})->group('LEAD-PERM-01');

it('LEAD-PERM-02: agent can list leads (view only)', function (): void {
    $agent = User::factory()->create();
    make_tenant_member($agent, $this->tenant, 'agent');

    $response = $this->actingAs($agent)->getJson('/api/v1/tenants/'.$this->tenant->id.'/leads');

    $response->assertOk();
})->group('LEAD-PERM-02');

it('LEAD-PERM-03: agent cannot create leads', function (): void {
    $agent = User::factory()->create();
    make_tenant_member($agent, $this->tenant, 'agent');

    $response = $this->actingAs($agent)->postJson('/api/v1/tenants/'.$this->tenant->id.'/leads', [
        'name' => 'Test',
    ]);

    $response->assertStatus(403);
})->group('LEAD-PERM-03');

it('LEAD-PERM-04: non-member gets 403', function (): void {
    $outsider = User::factory()->create();

    $response = $this->actingAs($outsider)->getJson('/api/v1/tenants/'.$this->tenant->id.'/leads');

    $response->assertStatus(403);
})->group('LEAD-PERM-04');

it('LEAD-PERM-05: unauthenticated gets 401', function (): void {
    $response = $this->getJson('/api/v1/tenants/'.$this->tenant->id.'/leads');

    $response->assertStatus(401);
})->group('LEAD-PERM-05');

it('LEAD-PERM-06: agent cannot delete leads', function (): void {
    $agent = User::factory()->create();
    make_tenant_member($agent, $this->tenant, 'agent');

    $lead = Lead::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $response = $this->actingAs($agent)->deleteJson('/api/v1/tenants/'.$this->tenant->id.'/leads/'.$lead->id);

    $response->assertStatus(403);
})->group('LEAD-PERM-06');
