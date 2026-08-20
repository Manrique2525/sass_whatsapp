<?php

declare(strict_types=1);

use App\Domain\Contacts\Models\Tag;
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
| Tag Multi-Tenancy API Tests (FASE 20 U2)
|--------------------------------------------------------------------------
|
| TAG-MT-U2-01..10 — Cross-tenant isolation via HTTP API.
| Corren en SQLite :memory:.
|
*/

function tag_url_mt(Tenant $tenant, ?string $tagId = null): string
{
    $base = '/api/v1/tenants/'.$tenant->id.'/tags';

    return $tagId !== null ? "{$base}/{$tagId}" : $base;
}

it('TAG-MT-U2-01: tenant A tags not visible to tenant B', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    make_tenant_member($ownerA, $tenantA, 'owner');
    make_tenant_member($ownerB, $tenantB, 'owner');

    TenantContext::setId($tenantA->id);
    Tag::query()->create(['tenant_id' => $tenantA->id, 'name' => 'VIP']);

    TenantContext::setId($tenantB->id);
    $response = $this->actingAs($ownerB)->getJson(tag_url_mt($tenantB));

    $response->assertOk();
    $response->assertJsonPath('meta.total', 0);
})->group('TAG-MT-U2-01');

it('TAG-MT-U2-02: tenant A create, tenant B cannot show', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    make_tenant_member($ownerA, $tenantA, 'owner');
    make_tenant_member($ownerB, $tenantB, 'owner');

    TenantContext::setId($tenantA->id);
    $tagA = Tag::query()->create(['tenant_id' => $tenantA->id, 'name' => 'VIP']);

    TenantContext::setId($tenantB->id);
    $response = $this->actingAs($ownerB)->getJson(tag_url_mt($tenantB, $tagA->id));

    $response->assertNotFound();
})->group('TAG-MT-U2-02');

it('TAG-MT-U2-03: tenant A cannot update tenant B tag', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    make_tenant_member($ownerA, $tenantA, 'owner');
    make_tenant_member($ownerB, $tenantB, 'owner');

    TenantContext::setId($tenantB->id);
    $tagB = Tag::query()->create(['tenant_id' => $tenantB->id, 'name' => 'VIP']);

    TenantContext::setId($tenantA->id);
    $response = $this->actingAs($ownerA)->patchJson(tag_url_mt($tenantA, $tagB->id), [
        'name' => 'Hacked',
    ]);

    $response->assertNotFound();

    $tagB->refresh();
    expect($tagB->name)->toBe('VIP');
})->group('TAG-MT-U2-03');

it('TAG-MT-U2-04: tenant A cannot delete tenant B tag', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    make_tenant_member($ownerA, $tenantA, 'owner');
    make_tenant_member($ownerB, $tenantB, 'owner');

    TenantContext::setId($tenantB->id);
    $tagB = Tag::query()->create(['tenant_id' => $tenantB->id, 'name' => 'VIP']);

    TenantContext::setId($tenantA->id);
    $response = $this->actingAs($ownerA)->deleteJson(tag_url_mt($tenantA, $tagB->id));

    $response->assertNotFound();

    $this->assertDatabaseHas('tags', ['id' => $tagB->id, 'tenant_id' => $tenantB->id]);
})->group('TAG-MT-U2-04');

it('TAG-MT-U2-05: same name allowed across tenants', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    make_tenant_member($ownerA, $tenantA, 'owner');
    make_tenant_member($ownerB, $tenantB, 'owner');

    TenantContext::setId($tenantA->id);
    $createA = $this->actingAs($ownerA)->postJson(tag_url_mt($tenantA), [
        'name' => 'VIP',
    ]);
    $createA->assertCreated();

    TenantContext::setId($tenantB->id);
    $createB = $this->actingAs($ownerB)->postJson(tag_url_mt($tenantB), [
        'name' => 'VIP',
    ]);
    $createB->assertCreated();

    expect(Tag::withoutGlobalScopes()->where('name', 'VIP')->count())->toBe(2);
})->group('TAG-MT-U2-05');

it('TAG-MT-U2-06: tenant A search cannot find B tags', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    make_tenant_member($ownerA, $tenantA, 'owner');
    make_tenant_member($ownerB, $tenantB, 'owner');

    TenantContext::setId($tenantB->id);
    Tag::query()->create(['tenant_id' => $tenantB->id, 'name' => 'Unique_B_Tag']);

    TenantContext::setId($tenantA->id);
    $response = $this->actingAs($ownerA)->getJson(tag_url_mt($tenantA).'?search=Unique_B_Tag');

    $response->assertOk();
    $response->assertJsonPath('meta.total', 0);
})->group('TAG-MT-U2-06');

it('TAG-MT-U2-07: non-member cannot access tenant tags', function (): void {
    $tenant = Tenant::factory()->create();
    $stranger = User::factory()->create();
    TenantContext::setId($tenant->id);

    $response = $this->actingAs($stranger)->getJson(tag_url_mt($tenant));

    $response->assertStatus(403);
})->group('TAG-MT-U2-07');

it('TAG-MT-U2-08: non-member cannot create tag', function (): void {
    $tenant = Tenant::factory()->create();
    $stranger = User::factory()->create();
    TenantContext::setId($tenant->id);

    $response = $this->actingAs($stranger)->postJson(tag_url_mt($tenant), [
        'name' => 'VIP',
    ]);

    $response->assertStatus(403);
})->group('TAG-MT-U2-08');

it('TAG-MT-U2-09: other tenant tag pagination isolated', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    make_tenant_member($ownerA, $tenantA, 'owner');
    make_tenant_member($ownerB, $tenantB, 'owner');

    TenantContext::setId($tenantA->id);
    for ($i = 0; $i < 20; $i++) {
        Tag::query()->create(['tenant_id' => $tenantA->id, 'name' => "Tag A {$i}"]);
    }

    TenantContext::setId($tenantB->id);
    for ($i = 0; $i < 5; $i++) {
        Tag::query()->create(['tenant_id' => $tenantB->id, 'name' => "Tag B {$i}"]);
    }

    TenantContext::setId($tenantA->id);
    $responseA = $this->actingAs($ownerA)->getJson(tag_url_mt($tenantA).'?per_page=10');
    $responseA->assertOk();
    $responseA->assertJsonPath('meta.total', 20);

    TenantContext::setId($tenantB->id);
    $responseB = $this->actingAs($ownerB)->getJson(tag_url_mt($tenantB).'?per_page=10');
    $responseB->assertOk();
    $responseB->assertJsonPath('meta.total', 5);
})->group('TAG-MT-U2-09');

it('TAG-MT-U2-10: agent can view own tenant tags', function (): void {
    $tenant = Tenant::factory()->create();
    $agent = User::factory()->create();
    make_tenant_member($agent, $tenant, 'agent');
    TenantContext::setId($tenant->id);

    Tag::query()->create(['tenant_id' => $tenant->id, 'name' => 'VIP']);
    Tag::query()->create(['tenant_id' => $tenant->id, 'name' => 'Premium']);

    $response = $this->actingAs($agent)->getJson(tag_url_mt($tenant));

    $response->assertOk();
    $response->assertJsonPath('meta.total', 2);
})->group('TAG-MT-U2-10');
