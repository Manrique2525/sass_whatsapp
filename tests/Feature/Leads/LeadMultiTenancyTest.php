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
| Lead Multi-Tenancy Tests (FASE 19 U2)
|--------------------------------------------------------------------------
|
| LEAD-MT-01..10 — Aislamiento de datos entre tenants.
| Corren en SQLite :memory:.
|
*/

beforeEach(function (): void {
    $this->tenantA = Tenant::factory()->create();
    $this->tenantB = Tenant::factory()->create();
    $this->ownerA = User::factory()->create();
    $this->ownerB = User::factory()->create();
    make_tenant_member($this->ownerA, $this->tenantA, 'owner');
    make_tenant_member($this->ownerB, $this->tenantB, 'owner');
});

it('LEAD-MT-01: tenant A cannot see tenant B leads', function (): void {
    TenantContext::setId($this->tenantB->id);

    Lead::factory()->create([
        'tenant_id' => $this->tenantB->id,
    ]);

    TenantContext::setId($this->tenantA->id);

    $response = $this->actingAs($this->ownerA)->getJson('/api/v1/tenants/'.$this->tenantA->id.'/leads');

    $response->assertOk();
    $response->assertJsonPath('meta.total', 0);
})->group('LEAD-MT-01');

it('LEAD-MT-02: tenant A cannot show tenant B lead', function (): void {
    TenantContext::setId($this->tenantB->id);

    $leadB = Lead::factory()->create([
        'tenant_id' => $this->tenantB->id,
    ]);

    TenantContext::setId($this->tenantA->id);

    $response = $this->actingAs($this->ownerA)->getJson('/api/v1/tenants/'.$this->tenantA->id.'/leads/'.$leadB->id);

    $response->assertNotFound();
})->group('LEAD-MT-02');

it('LEAD-MT-03: tenant A cannot update tenant B lead', function (): void {
    TenantContext::setId($this->tenantB->id);

    $leadB = Lead::factory()->create([
        'tenant_id' => $this->tenantB->id,
        'name' => 'Original',
    ]);

    TenantContext::setId($this->tenantA->id);

    $response = $this->actingAs($this->ownerA)->patchJson('/api/v1/tenants/'.$this->tenantA->id.'/leads/'.$leadB->id, [
        'name' => 'Hacked',
    ]);

    $response->assertNotFound();

    $leadB->refresh();
    expect($leadB->name)->toBe('Original');
})->group('LEAD-MT-03');

it('LEAD-MT-04: tenant A cannot delete tenant B lead', function (): void {
    TenantContext::setId($this->tenantB->id);

    $leadB = Lead::factory()->create([
        'tenant_id' => $this->tenantB->id,
    ]);

    TenantContext::setId($this->tenantA->id);

    $response = $this->actingAs($this->ownerA)->deleteJson('/api/v1/tenants/'.$this->tenantA->id.'/leads/'.$leadB->id);

    $response->assertNotFound();

    expect(Lead::withoutGlobalScopes()->find($leadB->id)->deleted_at)->toBeNull();
})->group('LEAD-MT-04');

it('LEAD-MT-05: each tenant has independent leads', function (): void {
    TenantContext::setId($this->tenantA->id);

    Lead::factory()->count(3)->create([
        'tenant_id' => $this->tenantA->id,
    ]);

    TenantContext::setId($this->tenantB->id);

    Lead::factory()->count(5)->create([
        'tenant_id' => $this->tenantB->id,
    ]);

    TenantContext::setId($this->tenantA->id);

    $responseA = $this->actingAs($this->ownerA)->getJson('/api/v1/tenants/'.$this->tenantA->id.'/leads');
    $responseA->assertOk();
    $responseA->assertJsonPath('meta.total', 3);

    TenantContext::setId($this->tenantB->id);

    $responseB = $this->actingAs($this->ownerB)->getJson('/api/v1/tenants/'.$this->tenantB->id.'/leads');
    $responseB->assertOk();
    $responseB->assertJsonPath('meta.total', 5);
})->group('LEAD-MT-05');

it('LEAD-MT-06: duplicate phone across tenants is allowed', function (): void {
    TenantContext::setId($this->tenantA->id);

    Lead::factory()->create([
        'tenant_id' => $this->tenantA->id,
        'phone' => '+529931234567',
    ]);

    TenantContext::setId($this->tenantB->id);

    $response = $this->actingAs($this->ownerB)->postJson('/api/v1/tenants/'.$this->tenantB->id.'/leads', [
        'name' => 'Cross-Tenant',
        'phone' => '+529931234567',
    ]);

    $response->assertCreated();
})->group('LEAD-MT-06');

it('LEAD-MT-07: duplicate email within same tenant returns 409', function (): void {
    TenantContext::setId($this->tenantA->id);

    Lead::factory()->create([
        'tenant_id' => $this->tenantA->id,
        'email' => 'same@example.com',
    ]);

    $response = $this->actingAs($this->ownerA)->postJson('/api/v1/tenants/'.$this->tenantA->id.'/leads', [
        'name' => 'Duplicate',
        'email' => 'same@example.com',
    ]);

    $response->assertStatus(409);
})->group('LEAD-MT-07');

it('LEAD-MT-08: creating lead auto-fills tenant_id', function (): void {
    TenantContext::setId($this->tenantA->id);

    $this->actingAs($this->ownerA)->postJson('/api/v1/tenants/'.$this->tenantA->id.'/leads', [
        'name' => 'Auto Tenant',
    ]);

    $lead = Lead::withoutGlobalScopes()->first();
    expect($lead->tenant_id)->toBe($this->tenantA->id);
})->group('LEAD-MT-08');

it('LEAD-MT-09: search only returns leads from current tenant', function (): void {
    TenantContext::setId($this->tenantA->id);

    Lead::factory()->create([
        'tenant_id' => $this->tenantA->id,
        'name' => 'Shared Name',
    ]);

    TenantContext::setId($this->tenantB->id);

    Lead::factory()->create([
        'tenant_id' => $this->tenantB->id,
        'name' => 'Shared Name',
    ]);

    TenantContext::setId($this->tenantA->id);

    $response = $this->actingAs($this->ownerA)->getJson('/api/v1/tenants/'.$this->tenantA->id.'/leads?search=Shared+Name');

    $response->assertOk();
    $response->assertJsonPath('meta.total', 1);
})->group('LEAD-MT-09');

it('LEAD-MT-10: soft-deleted lead excluded from tenant queries', function (): void {
    TenantContext::setId($this->tenantA->id);

    $lead = Lead::factory()->create([
        'tenant_id' => $this->tenantA->id,
    ]);
    $lead->delete();

    Lead::factory()->create([
        'tenant_id' => $this->tenantA->id,
    ]);

    $response = $this->actingAs($this->ownerA)->getJson('/api/v1/tenants/'.$this->tenantA->id.'/leads');

    $response->assertOk();
    $response->assertJsonPath('meta.total', 1);
})->group('LEAD-MT-10');
