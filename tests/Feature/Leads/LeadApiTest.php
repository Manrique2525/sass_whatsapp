<?php

declare(strict_types=1);

use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
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
| Lead API Tests (FASE 19 U2)
|--------------------------------------------------------------------------
|
| LEAD-API-01..20 — CRUD API, search, pagination, validation, resource.
| Corren en SQLite :memory:.
|
*/

function lead_url(Tenant $tenant, ?string $leadId = null): string
{
    $base = '/api/v1/tenants/'.$tenant->id.'/leads';

    return $leadId !== null ? "{$base}/{$leadId}" : $base;
}

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->owner = User::factory()->create();
    make_tenant_member($this->owner, $this->tenant, 'owner');
    TenantContext::setId($this->tenant->id);
});

it('LEAD-API-01: create returns 201 and persists', function (): void {
    $response = $this->actingAs($this->owner)->postJson(lead_url($this->tenant), [
        'name' => 'Juan Pérez',
        'phone' => '+529931234567',
        'email' => 'juan@example.com',
        'status' => 'new',
        'source' => 'manual',
        'notes' => 'Lead de prueba',
    ]);

    $response->assertCreated()->assertJson([
        'message' => 'Lead creado.',
        'lead' => [
            'name' => 'Juan Pérez',
            'phone' => '+529931234567',
            'email' => 'juan@example.com',
            'status' => 'new',
            'source' => 'manual',
            'notes' => 'Lead de prueba',
        ],
    ]);

    expect(Lead::withoutGlobalScopes()->count())->toBe(1);
})->group('LEAD-API-01');

it('LEAD-API-02: create with minimal fields', function (): void {
    $response = $this->actingAs($this->owner)->postJson(lead_url($this->tenant), [
        'name' => 'María López',
    ]);

    $response->assertCreated()->assertJson([
        'lead' => [
            'name' => 'María López',
            'status' => 'new',
        ],
    ]);

    $lead = Lead::withoutGlobalScopes()->first();
    expect($lead->phone)->toBeNull();
    expect($lead->email)->toBeNull();
    expect($lead->source)->toBeNull();
    expect($lead->notes)->toBeNull();
})->group('LEAD-API-02');

it('LEAD-API-03: create normalizes phone', function (): void {
    $response = $this->actingAs($this->owner)->postJson(lead_url($this->tenant), [
        'name' => 'Test',
        'phone' => '+52 993 123 4567',
    ]);

    $response->assertCreated();

    $lead = Lead::withoutGlobalScopes()->first();
    expect($lead->phone)->toBe('+529931234567');
})->group('LEAD-API-03');

it('LEAD-API-04: create normalizes email', function (): void {
    $response = $this->actingAs($this->owner)->postJson(lead_url($this->tenant), [
        'name' => 'Test',
        'email' => ' Juan@Example.COM ',
    ]);

    $response->assertCreated();

    $lead = Lead::withoutGlobalScopes()->first();
    expect($lead->email)->toBe('juan@example.com');
})->group('LEAD-API-04');

it('LEAD-API-05: list returns leads', function (): void {
    Lead::factory()->count(3)->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $response = $this->actingAs($this->owner)->getJson(lead_url($this->tenant));

    $response->assertOk()->assertJsonStructure([
        'leads' => [['id', 'name', 'phone', 'email', 'status', 'source', 'notes']],
        'meta' => ['current_page', 'last_page', 'per_page', 'total'],
    ]);

    $response->assertJsonPath('meta.total', 3);
})->group('LEAD-API-05');

it('LEAD-API-06: pagination works', function (): void {
    Lead::factory()->count(25)->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $response = $this->actingAs($this->owner)->getJson(lead_url($this->tenant).'?per_page=10');

    $response->assertOk();
    $response->assertJsonPath('meta.per_page', 10);
    $response->assertJsonPath('meta.total', 25);
    expect($response->json('leads'))->toHaveCount(10);
})->group('LEAD-API-06');

it('LEAD-API-07: search filters by name', function (): void {
    Lead::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Juan Pérez',
    ]);
    Lead::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'María López',
    ]);

    $response = $this->actingAs($this->owner)->getJson(lead_url($this->tenant).'?search=Juan');

    $response->assertOk();
    $response->assertJsonPath('meta.total', 1);
})->group('LEAD-API-07');

it('LEAD-API-08: search filters by phone', function (): void {
    Lead::factory()->create([
        'tenant_id' => $this->tenant->id,
        'phone' => '+529931234567',
    ]);
    Lead::factory()->create([
        'tenant_id' => $this->tenant->id,
        'phone' => '+541155554444',
    ]);

    $response = $this->actingAs($this->owner)->getJson(lead_url($this->tenant).'?search=993');

    $response->assertOk();
    $response->assertJsonPath('meta.total', 1);
})->group('LEAD-API-08');

it('LEAD-API-09: search filters by email', function (): void {
    Lead::factory()->create([
        'tenant_id' => $this->tenant->id,
        'email' => 'juan@example.com',
    ]);
    Lead::factory()->create([
        'tenant_id' => $this->tenant->id,
        'email' => 'maria@test.com',
    ]);

    $response = $this->actingAs($this->owner)->getJson(lead_url($this->tenant).'?search=example');

    $response->assertOk();
    $response->assertJsonPath('meta.total', 1);
})->group('LEAD-API-09');

it('LEAD-API-10: status filter works', function (): void {
    Lead::factory()->asNew()->create([
        'tenant_id' => $this->tenant->id,
    ]);
    Lead::factory()->contacted()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $response = $this->actingAs($this->owner)->getJson(lead_url($this->tenant).'?status=new');

    $response->assertOk();
    $response->assertJsonPath('meta.total', 1);
})->group('LEAD-API-10');

it('LEAD-API-11: source filter works', function (): void {
    Lead::factory()->create([
        'tenant_id' => $this->tenant->id,
        'source' => 'manual',
    ]);
    Lead::factory()->create([
        'tenant_id' => $this->tenant->id,
        'source' => 'web',
    ]);

    $response = $this->actingAs($this->owner)->getJson(lead_url($this->tenant).'?source=manual');

    $response->assertOk();
    $response->assertJsonPath('meta.total', 1);
})->group('LEAD-API-11');

it('LEAD-API-12: show returns lead', function (): void {
    $lead = Lead::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $response = $this->actingAs($this->owner)->getJson(lead_url($this->tenant, $lead->id));

    $response->assertOk()->assertJson([
        'lead' => [
            'id' => $lead->id,
            'name' => $lead->name,
        ],
    ]);
})->group('LEAD-API-12');

it('LEAD-API-13: show non-existent returns 404', function (): void {
    $fakeId = (string) Str::uuid();

    $response = $this->actingAs($this->owner)->getJson(lead_url($this->tenant, $fakeId));

    $response->assertNotFound();
})->group('LEAD-API-13');

it('LEAD-API-14: update name', function (): void {
    $lead = Lead::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Old Name',
    ]);

    $response = $this->actingAs($this->owner)->patchJson(lead_url($this->tenant, $lead->id), [
        'name' => 'New Name',
    ]);

    $response->assertOk();

    $lead->refresh();
    expect($lead->name)->toBe('New Name');
})->group('LEAD-API-14');

it('LEAD-API-15: update status via valid transition', function (): void {
    $lead = Lead::factory()->asNew()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $response = $this->actingAs($this->owner)->patchJson(lead_url($this->tenant, $lead->id), [
        'status' => 'contacted',
    ]);

    $response->assertOk();

    $lead->refresh();
    expect($lead->status)->toBe(LeadStatus::Contacted);
})->group('LEAD-API-15');

it('LEAD-API-16: update invalid status transition returns 422', function (): void {
    $lead = Lead::factory()->asNew()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $response = $this->actingAs($this->owner)->patchJson(lead_url($this->tenant, $lead->id), [
        'status' => 'won',
    ]);

    $response->assertStatus(422)->assertJson([
        'code' => 'LEAD_INVALID_TRANSITION',
    ]);
})->group('LEAD-API-16');

it('LEAD-API-17: soft delete', function (): void {
    $lead = Lead::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $response = $this->actingAs($this->owner)->deleteJson(lead_url($this->tenant, $lead->id));

    $response->assertOk()->assertJson(['message' => 'Lead eliminado.']);

    expect(Lead::withoutGlobalScopes()->find($lead->id)->deleted_at)->not->toBeNull();
})->group('LEAD-API-17');

it('LEAD-API-18: deleted lead show returns 404', function (): void {
    $lead = Lead::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);
    $lead->delete();

    $response = $this->actingAs($this->owner)->getJson(lead_url($this->tenant, $lead->id));

    $response->assertNotFound();
})->group('LEAD-API-18');

it('LEAD-API-19: duplicate phone returns 409', function (): void {
    Lead::factory()->create([
        'tenant_id' => $this->tenant->id,
        'phone' => '+529931234567',
    ]);

    $response = $this->actingAs($this->owner)->postJson(lead_url($this->tenant), [
        'name' => 'Duplicate',
        'phone' => '+529931234567',
    ]);

    $response->assertStatus(409)->assertJson([
        'code' => 'LEAD_DUPLICATE',
    ]);
})->group('LEAD-API-19');

it('LEAD-API-20: duplicate email returns 409', function (): void {
    Lead::factory()->create([
        'tenant_id' => $this->tenant->id,
        'email' => 'duplicate@example.com',
    ]);

    $response = $this->actingAs($this->owner)->postJson(lead_url($this->tenant), [
        'name' => 'Duplicate',
        'email' => 'duplicate@example.com',
    ]);

    $response->assertStatus(409)->assertJson([
        'code' => 'LEAD_DUPLICATE',
    ]);
})->group('LEAD-API-20');

it('LEAD-API-21: resource hides internals', function (): void {
    $lead = Lead::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $response = $this->actingAs($this->owner)->getJson(lead_url($this->tenant, $lead->id));

    $response->assertOk();

    $data = $response->json('lead');
    expect($data)->not->toHaveKey('tenant_id');
    expect($data)->not->toHaveKey('deleted_at');
    expect($data)->toHaveKey('id');
    expect($data)->toHaveKey('name');
    expect($data)->toHaveKey('phone');
    expect($data)->toHaveKey('email');
    expect($data)->toHaveKey('status');
    expect($data)->toHaveKey('source');
    expect($data)->toHaveKey('notes');
    expect($data)->toHaveKey('created_at');
    expect($data)->toHaveKey('updated_at');
})->group('LEAD-API-21');

it('LEAD-API-22: name required on create', function (): void {
    $response = $this->actingAs($this->owner)->postJson(lead_url($this->tenant), [
        'phone' => '+529931234567',
    ]);

    $response->assertStatus(422);
})->group('LEAD-API-22');

it('LEAD-API-23: invalid status rejected on create', function (): void {
    $response = $this->actingAs($this->owner)->postJson(lead_url($this->tenant), [
        'name' => 'Test',
        'status' => 'invalid_status',
    ]);

    $response->assertStatus(422);
})->group('LEAD-API-23');

it('LEAD-API-24: invalid source rejected', function (): void {
    $response = $this->actingAs($this->owner)->postJson(lead_url($this->tenant), [
        'name' => 'Test',
        'source' => 'invalid_source',
    ]);

    $response->assertStatus(422);
})->group('LEAD-API-24');

it('LEAD-API-25: notes max length enforced', function (): void {
    $response = $this->actingAs($this->owner)->postJson(lead_url($this->tenant), [
        'name' => 'Test',
        'notes' => str_repeat('a', 4097),
    ]);

    $response->assertStatus(422);
})->group('LEAD-API-25');
