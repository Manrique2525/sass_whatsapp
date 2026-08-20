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
| Tag Permission Tests (FASE 20 U2)
|--------------------------------------------------------------------------
|
| TAG-PERM-01..06 — Permission matrix for tags.view / tags.manage.
| Corren en SQLite :memory:.
|
*/

function tag_url_p(Tenant $tenant, ?string $tagId = null): string
{
    $base = '/api/v1/tenants/'.$tenant->id.'/tags';

    return $tagId !== null ? "{$base}/{$tagId}" : $base;
}

it('TAG-PERM-01: owner has view and manage', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');
    TenantContext::setId($tenant->id);

    $list = $this->actingAs($owner)->getJson(tag_url_p($tenant));
    $list->assertOk();

    $create = $this->actingAs($owner)->postJson(tag_url_p($tenant), [
        'name' => 'VIP',
    ]);
    $create->assertCreated();
})->group('TAG-PERM-01');

it('TAG-PERM-02: admin has view and manage', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = User::factory()->create();
    make_tenant_member($admin, $tenant, 'admin');
    TenantContext::setId($tenant->id);

    $list = $this->actingAs($admin)->getJson(tag_url_p($tenant));
    $list->assertOk();

    $create = $this->actingAs($admin)->postJson(tag_url_p($tenant), [
        'name' => 'VIP',
    ]);
    $create->assertCreated();
})->group('TAG-PERM-02');

it('TAG-PERM-03: agent has view', function (): void {
    $tenant = Tenant::factory()->create();
    $agent = User::factory()->create();
    make_tenant_member($agent, $tenant, 'agent');
    TenantContext::setId($tenant->id);

    $list = $this->actingAs($agent)->getJson(tag_url_p($tenant));
    $list->assertOk();
})->group('TAG-PERM-03');

it('TAG-PERM-04: agent cannot create (403)', function (): void {
    $tenant = Tenant::factory()->create();
    $agent = User::factory()->create();
    make_tenant_member($agent, $tenant, 'agent');
    TenantContext::setId($tenant->id);

    $response = $this->actingAs($agent)->postJson(tag_url_p($tenant), [
        'name' => 'VIP',
    ]);

    $response->assertStatus(403)->assertJson([
        'code' => 'PERMISSION_DENIED',
    ]);
})->group('TAG-PERM-04');

it('TAG-PERM-05: agent cannot update (403)', function (): void {
    $tenant = Tenant::factory()->create();
    $agent = User::factory()->create();
    make_tenant_member($agent, $tenant, 'agent');
    TenantContext::setId($tenant->id);

    $tag = Tag::query()->create(['tenant_id' => $tenant->id, 'name' => 'VIP']);

    $response = $this->actingAs($agent)->patchJson(tag_url_p($tenant, $tag->id), [
        'name' => 'hacked',
    ]);

    $response->assertStatus(403);
})->group('TAG-PERM-05');

it('TAG-PERM-06: agent cannot delete (403)', function (): void {
    $tenant = Tenant::factory()->create();
    $agent = User::factory()->create();
    make_tenant_member($agent, $tenant, 'agent');
    TenantContext::setId($tenant->id);

    $tag = Tag::query()->create(['tenant_id' => $tenant->id, 'name' => 'VIP']);

    $response = $this->actingAs($agent)->deleteJson(tag_url_p($tenant, $tag->id));

    $response->assertStatus(403);
})->group('TAG-PERM-06');
