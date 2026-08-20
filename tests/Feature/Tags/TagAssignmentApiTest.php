<?php

declare(strict_types=1);

use App\Domain\Contacts\Models\Contact;
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
| Tag Assignment API Tests (FASE 20 U3)
|--------------------------------------------------------------------------
|
| TAG-ASG-01..13 — Batch assign, remove, idempotency, validation,
| resource response, cross-tenant isolation.
| Corren en SQLite :memory:.
|
*/

afterEach(function (): void {
    TenantContext::clear();
});

function asg_tenant_url(Tenant $tenant, ?string $contactId = null, ?string $tagId = null): string
{
    $base = '/api/v1/tenants/'.$tenant->id.'/contacts/'.$contactId.'/tags';

    return $tagId !== null ? "{$base}/{$tagId}" : $base;
}

function create_asg_tenant(): Tenant
{
    return Tenant::factory()->create();
}

function create_asg_owner(Tenant $tenant): User
{
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    return $owner;
}

function create_asg_contact(Tenant $tenant, string $phone = '+529950000001'): Contact
{
    return TenantContext::withId($tenant->id, fn () => Contact::query()->create([
        'name' => 'Asg Contact',
        'phone' => $phone,
    ]));
}

function create_asg_tag(Tenant $tenant, string $name): Tag
{
    return TenantContext::withId($tenant->id, fn () => Tag::query()->create(['name' => $name]));
}

/**
 * Inserta un tag directamente en la DB con un tenant específico.
 * Útil para crear tags cross-tenant sin pasar por TenantContext::withId
 * (que es no-op si ya hay contexto activo).
 */
function insert_raw_tag(string $tenantId, string $name): string
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

it('TAG-ASG-01: batch assign tags to contact returns 200', function (): void {
    $tenant = create_asg_tenant();
    $owner = create_asg_owner($tenant);
    TenantContext::setId($tenant->id);

    $contact = create_asg_contact($tenant, '+529950000002');
    $tag = create_asg_tag($tenant, 'VIP');

    $response = $this->actingAs($owner)->postJson(
        asg_tenant_url($tenant, $contact->id),
        ['tag_ids' => [$tag->id]],
    );

    $response->assertOk()->assertJson([
        'message' => 'Tags asignados.',
        'contact' => ['id' => $contact->id],
    ]);

    $this->assertDatabaseHas('contact_tag', [
        'contact_id' => $contact->id,
        'tag_id' => $tag->id,
    ]);
})->group('TAG-ASG-01');

it('TAG-ASG-02: batch assign multiple tags', function (): void {
    $tenant = create_asg_tenant();
    $owner = create_asg_owner($tenant);
    TenantContext::setId($tenant->id);

    $contact = create_asg_contact($tenant, '+529950000003');
    $tag1 = create_asg_tag($tenant, 'VIP');
    $tag2 = create_asg_tag($tenant, 'Lead Caliente');

    $response = $this->actingAs($owner)->postJson(
        asg_tenant_url($tenant, $contact->id),
        ['tag_ids' => [$tag1->id, $tag2->id]],
    );

    $response->assertOk();

    $this->assertDatabaseHas('contact_tag', [
        'contact_id' => $contact->id,
        'tag_id' => $tag1->id,
    ]);
    $this->assertDatabaseHas('contact_tag', [
        'contact_id' => $contact->id,
        'tag_id' => $tag2->id,
    ]);
})->group('TAG-ASG-02');

it('TAG-ASG-03: batch assign is idempotent — same tag twice', function (): void {
    $tenant = create_asg_tenant();
    $owner = create_asg_owner($tenant);
    TenantContext::setId($tenant->id);

    $contact = create_asg_contact($tenant, '+529950000004');
    $tag = create_asg_tag($tenant, 'VIP');

    $this->actingAs($owner)->postJson(
        asg_tenant_url($tenant, $contact->id),
        ['tag_ids' => [$tag->id]],
    )->assertOk();

    $this->actingAs($owner)->postJson(
        asg_tenant_url($tenant, $contact->id),
        ['tag_ids' => [$tag->id]],
    )->assertOk();

    $count = DB::table('contact_tag')
        ->where('contact_id', $contact->id)
        ->where('tag_id', $tag->id)
        ->count();
    expect($count)->toBe(1);
})->group('TAG-ASG-03');

it('TAG-ASG-04: assign returns contact with tags loaded', function (): void {
    $tenant = create_asg_tenant();
    $owner = create_asg_owner($tenant);
    TenantContext::setId($tenant->id);

    $contact = create_asg_contact($tenant, '+529950000005');
    $tag = create_asg_tag($tenant, 'VIP');

    $response = $this->actingAs($owner)->postJson(
        asg_tenant_url($tenant, $contact->id),
        ['tag_ids' => [$tag->id]],
    );

    $response->assertOk()->assertJsonStructure([
        'contact' => ['tags' => [['id', 'name']]],
    ]);
})->group('TAG-ASG-04');

it('TAG-ASG-05: remove assigned tag returns 200', function (): void {
    $tenant = create_asg_tenant();
    $owner = create_asg_owner($tenant);
    TenantContext::setId($tenant->id);

    $contact = create_asg_contact($tenant, '+529950000006');
    $tag = create_asg_tag($tenant, 'VIP');
    $contact->tags()->attach($tag->id);

    $response = $this->actingAs($owner)->deleteJson(
        asg_tenant_url($tenant, $contact->id, $tag->id),
    );

    $response->assertOk()->assertJson([
        'message' => 'Tag removido.',
    ]);

    $this->assertDatabaseMissing('contact_tag', [
        'contact_id' => $contact->id,
        'tag_id' => $tag->id,
    ]);
})->group('TAG-ASG-05');

it('TAG-ASG-06: remove unassigned tag is idempotent — returns 200', function (): void {
    $tenant = create_asg_tenant();
    $owner = create_asg_owner($tenant);
    TenantContext::setId($tenant->id);

    $contact = create_asg_contact($tenant, '+529950000007');
    $tag = create_asg_tag($tenant, 'VIP');

    $response = $this->actingAs($owner)->deleteJson(
        asg_tenant_url($tenant, $contact->id, $tag->id),
    );

    $response->assertOk();
})->group('TAG-ASG-06');

it('TAG-ASG-07: validation — empty tag_ids array returns 422', function (): void {
    $tenant = create_asg_tenant();
    $owner = create_asg_owner($tenant);
    TenantContext::setId($tenant->id);

    $contact = create_asg_contact($tenant, '+529950000008');

    $response = $this->actingAs($owner)->postJson(
        asg_tenant_url($tenant, $contact->id),
        ['tag_ids' => []],
    );

    $response->assertUnprocessable()->assertJsonValidationErrors('tag_ids');
})->group('TAG-ASG-07');

it('TAG-ASG-08: validation — invalid UUID returns 422', function (): void {
    $tenant = create_asg_tenant();
    $owner = create_asg_owner($tenant);
    TenantContext::setId($tenant->id);

    $contact = create_asg_contact($tenant, '+529950000009');

    $response = $this->actingAs($owner)->postJson(
        asg_tenant_url($tenant, $contact->id),
        ['tag_ids' => ['not-a-uuid']],
    );

    $response->assertUnprocessable()->assertJsonValidationErrors('tag_ids.0');
})->group('TAG-ASG-08');

it('TAG-ASG-09: validation — duplicate tag_ids returns 422', function (): void {
    $tenant = create_asg_tenant();
    $owner = create_asg_owner($tenant);
    TenantContext::setId($tenant->id);

    $contact = create_asg_contact($tenant, '+529950000010');
    $tag = create_asg_tag($tenant, 'VIP');

    $response = $this->actingAs($owner)->postJson(
        asg_tenant_url($tenant, $contact->id),
        ['tag_ids' => [$tag->id, $tag->id]],
    );

    $response->assertUnprocessable()->assertJsonValidationErrors('tag_ids.1');
})->group('TAG-ASG-09');

it('TAG-ASG-10: validation — max 20 tag_ids enforced', function (): void {
    $tenant = create_asg_tenant();
    $owner = create_asg_owner($tenant);
    TenantContext::setId($tenant->id);

    $contact = create_asg_contact($tenant, '+529950000011');

    $tooMany = array_map(
        fn () => Str::uuid()->toString(),
        range(1, 21),
    );

    $response = $this->actingAs($owner)->postJson(
        asg_tenant_url($tenant, $contact->id),
        ['tag_ids' => $tooMany],
    );

    $response->assertUnprocessable()->assertJsonValidationErrors('tag_ids');
})->group('TAG-ASG-10');

it('TAG-ASG-11: cross-tenant tag assignment fails 403', function (): void {
    $tenantA = create_asg_tenant();
    $ownerA = create_asg_owner($tenantA);
    TenantContext::setId($tenantA->id);

    $contactA = create_asg_contact($tenantA, '+529950000012');
    $tagBId = insert_raw_tag($tenantB = (create_asg_tenant())->id, 'CrossTenant');

    $response = $this->actingAs($ownerA)->postJson(
        asg_tenant_url($tenantA, $contactA->id),
        ['tag_ids' => [$tagBId]],
    );

    $response->assertForbidden();
})->group('TAG-ASG-11');

it('TAG-ASG-12: cross-tenant tag removal returns 404 (not found in tenant)', function (): void {
    $tenantA = create_asg_tenant();
    $ownerA = create_asg_owner($tenantA);
    TenantContext::setId($tenantA->id);

    $contactA = create_asg_contact($tenantA, '+529950000013');
    $tagBId = insert_raw_tag((create_asg_tenant())->id, 'CrossTenant');

    $response = $this->actingAs($ownerA)->deleteJson(
        asg_tenant_url($tenantA, $contactA->id, $tagBId),
    );

    $response->assertNotFound();
})->group('TAG-ASG-12');

it('TAG-ASG-13: missing tag_id returns 403 (tag not found in tenant)', function (): void {
    $tenant = create_asg_tenant();
    $owner = create_asg_owner($tenant);
    TenantContext::setId($tenant->id);

    $contact = create_asg_contact($tenant, '+529950000014');
    $fakeTagId = Str::uuid()->toString();

    $response = $this->actingAs($owner)->postJson(
        asg_tenant_url($tenant, $contact->id),
        ['tag_ids' => [$fakeTagId]],
    );

    $response->assertForbidden();
})->group('TAG-ASG-13');
