<?php

declare(strict_types=1);

use App\Domain\Contacts\Models\Tag;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Tag Assignment Multi-Tenancy Tests (FASE 20 U3)
|--------------------------------------------------------------------------
|
| TAG-ASG-MT-01..10 — Isolation: Tenant A never sees/assigns/removes
| Tenant B's tags or contacts. Cross-tenant = 403/404.
| Corren en SQLite :memory:.
|
*/

afterEach(function (): void {
    TenantContext::clear();
});

function asg_mt_url(Tenant $tenant, string $contactId, ?string $tagId = null): string
{
    $base = '/api/v1/tenants/'.$tenant->id.'/contacts/'.$contactId.'/tags';

    return $tagId !== null ? "{$base}/{$tagId}" : $base;
}

function insert_mt_tag(string $tenantId, string $name): string
{
    $id = Str::uuid()->toString();
    DB::table('tags')->insert([
        'id' => $id,
        'tenant_id' => $tenantId,
        'name' => $name,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function insert_mt_contact(string $tenantId, string $phone): string
{
    $id = Str::uuid()->toString();
    DB::table('contacts')->insert([
        'id' => $id,
        'tenant_id' => $tenantId,
        'name' => 'MT Contact',
        'phone' => $phone,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function mt_tag(Tenant $tenant, string $name): Tag
{
    return TenantContext::withId($tenant->id, fn () => Tag::query()->create(['name' => $name]));
}

it('TAG-ASG-MT-01: assign cross-tenant tag returns 403', function (): void {
    $tenantA = Tenant::factory()->create();
    $ownerA = User::factory()->create();
    make_tenant_member($ownerA, $tenantA, 'owner');
    TenantContext::setId($tenantA->id);

    $tenantB = Tenant::factory()->create();
    $contactAId = insert_mt_contact($tenantA->id, '+529970000001');
    $tagBId = insert_mt_tag($tenantB->id, 'TagB');

    $this->actingAs($ownerA)->postJson(asg_mt_url($tenantA, $contactAId), [
        'tag_ids' => [$tagBId],
    ])->assertForbidden();
})->group('TAG-ASG-MT-01');

it('TAG-ASG-MT-02: remove cross-tenant tag returns 404 (not found in tenant)', function (): void {
    $tenantA = Tenant::factory()->create();
    $ownerA = User::factory()->create();
    make_tenant_member($ownerA, $tenantA, 'owner');
    TenantContext::setId($tenantA->id);

    $tenantB = Tenant::factory()->create();
    $contactAId = insert_mt_contact($tenantA->id, '+529970000002');
    $tagBId = insert_mt_tag($tenantB->id, 'TagB');

    $this->actingAs($ownerA)->deleteJson(asg_mt_url($tenantA, $contactAId, $tagBId))
        ->assertNotFound();
})->group('TAG-ASG-MT-02');

it('TAG-ASG-MT-03: cross-tenant contact assign returns 404', function (): void {
    $tenantA = Tenant::factory()->create();
    $ownerA = User::factory()->create();
    make_tenant_member($ownerA, $tenantA, 'owner');
    TenantContext::setId($tenantA->id);

    $tenantB = Tenant::factory()->create();
    $contactBId = insert_mt_contact($tenantB->id, '+529970000003');
    $tagA = mt_tag($tenantA, 'TagA');

    $this->actingAs($ownerA)->postJson(asg_mt_url($tenantA, $contactBId), [
        'tag_ids' => [$tagA->id],
    ])->assertNotFound();
})->group('TAG-ASG-MT-03');

it('TAG-ASG-MT-04: cross-tenant contact remove returns 404', function (): void {
    $tenantA = Tenant::factory()->create();
    $ownerA = User::factory()->create();
    make_tenant_member($ownerA, $tenantA, 'owner');
    TenantContext::setId($tenantA->id);

    $tenantB = Tenant::factory()->create();
    $contactBId = insert_mt_contact($tenantB->id, '+529970000004');
    $tagA = mt_tag($tenantA, 'TagA');

    $this->actingAs($ownerA)->deleteJson(asg_mt_url($tenantA, $contactBId, $tagA->id))
        ->assertNotFound();
})->group('TAG-ASG-MT-04');

it('TAG-ASG-MT-05: different tenants cannot see each others tags', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $ownerA = User::factory()->create();
    make_tenant_member($ownerA, $tenantA, 'owner');

    TenantContext::setId($tenantA->id);
    $tagA = mt_tag($tenantA, 'TagA');
    $contactAId = insert_mt_contact($tenantA->id, '+529970000005');
    $tagBId = insert_mt_tag($tenantB->id, 'TagB');

    $this->actingAs($ownerA)->postJson(asg_mt_url($tenantA, $contactAId), [
        'tag_ids' => [$tagA->id],
    ])->assertOk();

    $this->actingAs($ownerA)->postJson(asg_mt_url($tenantA, $contactAId), [
        'tag_ids' => [$tagBId],
    ])->assertForbidden();
})->group('TAG-ASG-MT-05');

it('TAG-ASG-MT-06: user without tenant membership gets 403', function (): void {
    $tenant = Tenant::factory()->create();
    $outsider = User::factory()->create();
    TenantContext::setId($tenant->id);

    $contactId = insert_mt_contact($tenant->id, '+529970000007');
    $tag = mt_tag($tenant, 'Tag');

    $this->actingAs($outsider)->postJson(asg_mt_url($tenant, $contactId), [
        'tag_ids' => [$tag->id],
    ])->assertForbidden();
})->group('TAG-ASG-MT-06');

it('TAG-ASG-MT-07: different tenants assign independently (no data leak)', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    make_tenant_member($ownerA, $tenantA, 'owner');
    make_tenant_member($ownerB, $tenantB, 'owner');

    TenantContext::setId($tenantA->id);
    $tagA = mt_tag($tenantA, 'VIP');
    $contactAId = insert_mt_contact($tenantA->id, '+529970000008');

    TenantContext::setId($tenantB->id);
    $tagB = mt_tag($tenantB, 'Premium');
    $contactBId = insert_mt_contact($tenantB->id, '+529970000009');

    $this->actingAs($ownerA)->postJson(asg_mt_url($tenantA, $contactAId), [
        'tag_ids' => [$tagA->id],
    ])->assertOk();

    $this->actingAs($ownerB)->postJson(asg_mt_url($tenantB, $contactBId), [
        'tag_ids' => [$tagB->id],
    ])->assertOk();

    $this->assertDatabaseHas('contact_tag', ['contact_id' => $contactAId, 'tag_id' => $tagA->id]);
    $this->assertDatabaseHas('contact_tag', ['contact_id' => $contactBId, 'tag_id' => $tagB->id]);
    $this->assertDatabaseMissing('contact_tag', ['contact_id' => $contactAId, 'tag_id' => $tagB->id]);
    $this->assertDatabaseMissing('contact_tag', ['contact_id' => $contactBId, 'tag_id' => $tagA->id]);
})->group('TAG-ASG-MT-07');

it('TAG-ASG-MT-08: tenant A cannot remove tenant B tag (not found in A = 404)', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $ownerA = User::factory()->create();
    make_tenant_member($ownerA, $tenantA, 'owner');
    TenantContext::setId($tenantA->id);

    $tagBId = insert_mt_tag($tenantB->id, 'TagB');
    $contactAId = insert_mt_contact($tenantA->id, '+529970000010');

    $this->actingAs($ownerA)->deleteJson(asg_mt_url($tenantA, $contactAId, $tagBId))
        ->assertNotFound();
})->group('TAG-ASG-MT-08');

it('TAG-ASG-MT-09: assignment does not affect other tenant contacts', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $ownerA = User::factory()->create();
    make_tenant_member($ownerA, $tenantA, 'owner');

    TenantContext::setId($tenantA->id);
    $tagA = mt_tag($tenantA, 'VIP');
    $contactAId = insert_mt_contact($tenantA->id, '+529970000011');
    $contactBId = insert_mt_contact($tenantB->id, '+529970000012');

    $this->actingAs($ownerA)->postJson(asg_mt_url($tenantA, $contactAId), [
        'tag_ids' => [$tagA->id],
    ])->assertOk();

    $this->assertDatabaseMissing('contact_tag', ['contact_id' => $contactBId, 'tag_id' => $tagA->id]);
})->group('TAG-ASG-MT-09');

it('TAG-ASG-MT-10: tag name uniqueness is per-tenant (same name OK in different tenants)', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    make_tenant_member($ownerA, $tenantA, 'owner');
    make_tenant_member($ownerB, $tenantB, 'owner');

    TenantContext::setId($tenantA->id);
    $tagA = mt_tag($tenantA, 'VIP');
    $contactAId = insert_mt_contact($tenantA->id, '+529970000013');

    TenantContext::setId($tenantB->id);
    $tagB = mt_tag($tenantB, 'VIP');
    $contactBId = insert_mt_contact($tenantB->id, '+529970000014');

    $this->actingAs($ownerA)->postJson(asg_mt_url($tenantA, $contactAId), [
        'tag_ids' => [$tagA->id],
    ])->assertOk();

    $this->actingAs($ownerB)->postJson(asg_mt_url($tenantB, $contactBId), [
        'tag_ids' => [$tagB->id],
    ])->assertOk();

    $this->assertDatabaseHas('contact_tag', ['contact_id' => $contactAId, 'tag_id' => $tagA->id]);
    $this->assertDatabaseHas('contact_tag', ['contact_id' => $contactBId, 'tag_id' => $tagB->id]);
    expect($tagA->id)->not->toBe($tagB->id);
})->group('TAG-ASG-MT-10');
