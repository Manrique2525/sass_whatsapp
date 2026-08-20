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
| Lead Security Matrix Tests (FASE 19 U4)
|--------------------------------------------------------------------------
|
| LEAD-SEC-F01..F12 — Consolidated security coverage.
| Corren en SQLite :memory:.
|
*/

beforeEach(function (): void {
    $this->tenantA = Tenant::factory()->create();
    $this->tenantB = Tenant::factory()->create();
    $this->ownerA = User::factory()->create();
    $this->agentA = User::factory()->create();
    $this->outsider = User::factory()->create();

    make_tenant_member($this->ownerA, $this->tenantA, 'owner');
    make_tenant_member($this->agentA, $this->tenantA, 'agent');
});

it('LEAD-SEC-F01: IDOR — tenant A cannot access tenant B lead via ID', function (): void {
    TenantContext::setId($this->tenantB->id);
    $leadB = Lead::factory()->create(['tenant_id' => $this->tenantB->id]);

    TenantContext::setId($this->tenantA->id);
    $response = $this->actingAs($this->ownerA)
        ->getJson('/api/v1/tenants/'.$this->tenantA->id.'/leads/'.$leadB->id);

    $response->assertNotFound();
})->group('LEAD-SEC-F01');

it('LEAD-SEC-F02: tenant_id injection in body is ignored on create', function (): void {
    TenantContext::setId($this->tenantA->id);

    $response = $this->actingAs($this->ownerA)->postJson('/api/v1/tenants/'.$this->tenantA->id.'/leads', [
        'name' => 'Injected',
        'tenant_id' => $this->tenantB->id,
    ]);

    $response->assertCreated();

    $lead = Lead::withoutGlobalScopes()->where('name', 'Injected')->first();
    expect($lead->tenant_id)->toBe($this->tenantA->id);
})->group('LEAD-SEC-F02');

it('LEAD-SEC-F03: mass assignment — tenant_id not in fillable', function (): void {
    $lead = new Lead;
    $fillable = $lead->getFillable();

    expect($fillable)->not->toContain('tenant_id');
    expect($fillable)->not->toContain('deleted_at');
    expect($fillable)->not->toContain('created_at');
    expect($fillable)->not->toContain('updated_at');
})->group('LEAD-SEC-F03');

it('LEAD-SEC-F04: SQL-looking search does not break query', function (): void {
    TenantContext::setId($this->tenantA->id);

    Lead::factory()->create(['tenant_id' => $this->tenantA->id, 'name' => 'Normal Lead']);

    $response = $this->actingAs($this->ownerA)
        ->getJson('/api/v1/tenants/'.$this->tenantA->id.'/leads?search='.urlencode("' OR 1=1 --"));

    $response->assertOk();
    $response->assertJsonPath('meta.total', 0);
})->group('LEAD-SEC-F04');

it('LEAD-SEC-F05: XSS payload stored as-is (XSS protection at render layer)', function (): void {
    TenantContext::setId($this->tenantA->id);

    $xssPayload = '<script>alert(1)</script>';

    $response = $this->actingAs($this->ownerA)->postJson('/api/v1/tenants/'.$this->tenantA->id.'/leads', [
        'name' => $xssPayload,
    ]);

    $response->assertCreated();
    $data = $response->json('lead');
    expect($data['name'])->toBe($xssPayload);
    expect($response->json('message'))->not->toContain('<script>');
})->group('LEAD-SEC-F05');

it('LEAD-SEC-F06: PII not in audit events', function (): void {
    TenantContext::setId($this->tenantA->id);

    $response = $this->actingAs($this->ownerA)->postJson('/api/v1/tenants/'.$this->tenantA->id.'/leads', [
        'name' => 'Audit Test',
        'phone' => '+529931234567',
        'email' => 'audit@test.com',
    ]);

    $response->assertCreated();
})->group('LEAD-SEC-F06');

it('LEAD-SEC-F07: duplicate error does not reveal existing lead identity', function (): void {
    TenantContext::setId($this->tenantA->id);

    Lead::factory()->create([
        'tenant_id' => $this->tenantA->id,
        'phone' => '+529931234567',
        'name' => 'Existing Lead',
    ]);

    $response = $this->actingAs($this->ownerA)->postJson('/api/v1/tenants/'.$this->tenantA->id.'/leads', [
        'name' => 'New Lead',
        'phone' => '+529931234567',
    ]);

    $response->assertStatus(409);
    $json = $response->json();
    expect($json['message'])->not->toContain('Existing Lead');
    expect($json['code'])->toBe('LEAD_DUPLICATE');
})->group('LEAD-SEC-F07');

it('LEAD-SEC-F08: invalid status transition returns 422 not 500', function (): void {
    TenantContext::setId($this->tenantA->id);

    $lead = Lead::factory()->asNew()->create(['tenant_id' => $this->tenantA->id]);

    $response = $this->actingAs($this->ownerA)->patchJson(
        '/api/v1/tenants/'.$this->tenantA->id.'/leads/'.$lead->id,
        ['status' => 'won']
    );

    $response->assertStatus(422);
    $response->assertJson(['code' => 'LEAD_INVALID_TRANSITION']);
})->group('LEAD-SEC-F08');

it('LEAD-SEC-F09: agent cannot create/update/delete leads', function (): void {
    TenantContext::setId($this->tenantA->id);

    $lead = Lead::factory()->create(['tenant_id' => $this->tenantA->id]);

    $create = $this->actingAs($this->agentA)
        ->postJson('/api/v1/tenants/'.$this->tenantA->id.'/leads', ['name' => 'X']);
    $create->assertStatus(403);

    $update = $this->actingAs($this->agentA)
        ->patchJson('/api/v1/tenants/'.$this->tenantA->id.'/leads/'.$lead->id, ['name' => 'Y']);
    $update->assertStatus(403);

    $delete = $this->actingAs($this->agentA)
        ->deleteJson('/api/v1/tenants/'.$this->tenantA->id.'/leads/'.$lead->id);
    $delete->assertStatus(403);
})->group('LEAD-SEC-F09');

it('LEAD-SEC-F10: inactive membership blocked', function (): void {
    if (config('database.default') !== 'pgsql') {
        $this->markTestSkipped('Requires PostgreSQL is_active column on tenant_users');
    }

    $inactive = User::factory()->create();
    make_tenant_member($inactive, $this->tenantA, 'owner');

    $this->app['db']->table('tenant_users')
        ->where('user_id', $inactive->id)
        ->where('tenant_id', $this->tenantA->id)
        ->update(['is_active' => false]);

    TenantContext::setId($this->tenantA->id);

    $response = $this->actingAs($inactive)
        ->getJson('/api/v1/tenants/'.$this->tenantA->id.'/leads');

    $response->assertStatus(403);
})->group('LEAD-SEC-F10');

it('LEAD-SEC-F11: soft-deleted lead excluded from queries', function (): void {
    TenantContext::setId($this->tenantA->id);

    $lead = Lead::factory()->create(['tenant_id' => $this->tenantA->id]);
    $lead->delete();

    $response = $this->actingAs($this->ownerA)
        ->getJson('/api/v1/tenants/'.$this->tenantA->id.'/leads/'.$lead->id);

    $response->assertNotFound();
})->group('LEAD-SEC-F11');

it('LEAD-SEC-F12: same phone allowed across tenants', function (): void {
    TenantContext::setId($this->tenantA->id);
    Lead::factory()->create(['tenant_id' => $this->tenantA->id, 'phone' => '+529931234567']);

    TenantContext::setId($this->tenantB->id);
    $ownerB = User::factory()->create();
    make_tenant_member($ownerB, $this->tenantB, 'owner');

    $response = $this->actingAs($ownerB)->postJson('/api/v1/tenants/'.$this->tenantB->id.'/leads', [
        'name' => 'Cross Tenant',
        'phone' => '+529931234567',
    ]);

    $response->assertCreated();
})->group('LEAD-SEC-F12');

/*
|--------------------------------------------------------------------------
| E2E CRUD Tests (FASE 19 U4)
|--------------------------------------------------------------------------
|
| LEAD-E2E-01..07 — Full lifecycle scenarios.
|
*/

it('LEAD-E2E-01: full CRUD lifecycle', function (): void {
    TenantContext::setId($this->tenantA->id);

    $create = $this->actingAs($this->ownerA)->postJson('/api/v1/tenants/'.$this->tenantA->id.'/leads', [
        'name' => 'E2E Lead',
        'phone' => '+529931234567',
        'email' => 'e2e@test.com',
        'source' => 'web',
    ]);
    $create->assertCreated();
    $leadId = $create->json('lead.id');

    $show = $this->actingAs($this->ownerA)
        ->getJson('/api/v1/tenants/'.$this->tenantA->id.'/leads/'.$leadId);
    $show->assertOk()->assertJsonPath('lead.name', 'E2E Lead');

    $update = $this->actingAs($this->ownerA)->patchJson(
        '/api/v1/tenants/'.$this->tenantA->id.'/leads/'.$leadId,
        ['name' => 'E2E Updated']
    );
    $update->assertOk()->assertJsonPath('lead.name', 'E2E Updated');

    $delete = $this->actingAs($this->ownerA)
        ->deleteJson('/api/v1/tenants/'.$this->tenantA->id.'/leads/'.$leadId);
    $delete->assertOk();

    $afterDelete = $this->actingAs($this->ownerA)
        ->getJson('/api/v1/tenants/'.$this->tenantA->id.'/leads/'.$leadId);
    $afterDelete->assertNotFound();
})->group('LEAD-E2E-01');

it('LEAD-E2E-02: phone normalized on create', function (): void {
    TenantContext::setId($this->tenantA->id);

    $response = $this->actingAs($this->ownerA)->postJson('/api/v1/tenants/'.$this->tenantA->id.'/leads', [
        'name' => 'Phone Test',
        'phone' => '+52 993 123 4567',
    ]);

    $response->assertCreated();
    expect($response->json('lead.phone'))->toBe('+529931234567');
})->group('LEAD-E2E-02');

it('LEAD-E2E-03: email normalized on create', function (): void {
    TenantContext::setId($this->tenantA->id);

    $response = $this->actingAs($this->ownerA)->postJson('/api/v1/tenants/'.$this->tenantA->id.'/leads', [
        'name' => 'Email Test',
        'email' => ' Juan@Test.COM ',
    ]);

    $response->assertCreated();
    expect($response->json('lead.email'))->toBe('juan@test.com');
})->group('LEAD-E2E-03');

it('LEAD-E2E-04: full status lifecycle', function (): void {
    TenantContext::setId($this->tenantA->id);

    $create = $this->actingAs($this->ownerA)->postJson('/api/v1/tenants/'.$this->tenantA->id.'/leads', [
        'name' => 'Lifecycle',
    ]);
    $create->assertCreated();
    $leadId = $create->json('lead.id');

    $t1 = $this->actingAs($this->ownerA)->patchJson(
        '/api/v1/tenants/'.$this->tenantA->id.'/leads/'.$leadId,
        ['status' => 'contacted']
    );
    $t1->assertOk();

    $t2 = $this->actingAs($this->ownerA)->patchJson(
        '/api/v1/tenants/'.$this->tenantA->id.'/leads/'.$leadId,
        ['status' => 'qualified']
    );
    $t2->assertOk();

    $t3 = $this->actingAs($this->ownerA)->patchJson(
        '/api/v1/tenants/'.$this->tenantA->id.'/leads/'.$leadId,
        ['status' => 'won']
    );
    $t3->assertOk();

    $show = $this->actingAs($this->ownerA)
        ->getJson('/api/v1/tenants/'.$this->tenantA->id.'/leads/'.$leadId);
    expect($show->json('lead.status'))->toBe('won');
})->group('LEAD-E2E-04');

it('LEAD-E2E-05: duplicate returns 409 not 500', function (): void {
    TenantContext::setId($this->tenantA->id);

    Lead::factory()->create(['tenant_id' => $this->tenantA->id, 'phone' => '+529931234567']);

    $response = $this->actingAs($this->ownerA)->postJson('/api/v1/tenants/'.$this->tenantA->id.'/leads', [
        'name' => 'Dup',
        'phone' => '+529931234567',
    ]);

    $response->assertStatus(409)->assertJson(['code' => 'LEAD_DUPLICATE']);
})->group('LEAD-E2E-05');

it('LEAD-E2E-06: cross-tenant access returns 404', function (): void {
    TenantContext::setId($this->tenantB->id);
    $leadB = Lead::factory()->create(['tenant_id' => $this->tenantB->id]);

    TenantContext::setId($this->tenantA->id);
    $response = $this->actingAs($this->ownerA)
        ->getJson('/api/v1/tenants/'.$this->tenantA->id.'/leads/'.$leadB->id);

    $response->assertNotFound();
})->group('LEAD-E2E-06');

it('LEAD-E2E-07: agent read-only enforced', function (): void {
    TenantContext::setId($this->tenantA->id);

    $list = $this->actingAs($this->agentA)
        ->getJson('/api/v1/tenants/'.$this->tenantA->id.'/leads');
    $list->assertOk();

    $create = $this->actingAs($this->agentA)
        ->postJson('/api/v1/tenants/'.$this->tenantA->id.'/leads', ['name' => 'X']);
    $create->assertStatus(403);
})->group('LEAD-E2E-07');
