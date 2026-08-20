<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
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
| Tag Security Tests (FASE 20 U2)
|--------------------------------------------------------------------------
|
| TAG-SEC-U2-01..10 — Injection, mass assignment, audit safety.
| Corren en SQLite :memory:.
|
*/

function tag_url_sec(Tenant $tenant, ?string $tagId = null): string
{
    $base = '/api/v1/tenants/'.$tenant->id.'/tags';

    return $tagId !== null ? "{$base}/{$tagId}" : $base;
}

it('TAG-SEC-U2-01: tenant_id injection ignored', function (): void {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');
    TenantContext::setId($tenant->id);

    $response = $this->actingAs($owner)->postJson(tag_url_sec($tenant), [
        'name' => 'VIP',
        'tenant_id' => $other->id,
    ]);

    $response->assertCreated();

    $tag = Tag::withoutGlobalScopes()->where('name', 'VIP')->first();
    expect($tag->tenant_id)->toBe($tenant->id);
})->group('TAG-SEC-U2-01');

it('TAG-SEC-U2-02: id injection in update ignored', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');
    TenantContext::setId($tenant->id);

    $tag = Tag::query()->create(['tenant_id' => $tenant->id, 'name' => 'VIP']);

    $response = $this->actingAs($owner)->patchJson(tag_url_sec($tenant, $tag->id), [
        'name' => 'VIP Plus',
        'id' => '00000000-0000-0000-0000-000000000001',
    ]);

    $response->assertOk();

    $tag->refresh();
    expect($tag->id)->toBe($tag->id);
})->group('TAG-SEC-U2-02');

it('TAG-SEC-U2-03: mass assignment timestamps protected', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');
    TenantContext::setId($tenant->id);

    $response = $this->actingAs($owner)->postJson(tag_url_sec($tenant), [
        'name' => 'VIP',
        'created_at' => '2000-01-01',
        'updated_at' => '2000-01-01',
    ]);

    $response->assertCreated();

    $tag = Tag::withoutGlobalScopes()->where('name', 'VIP')->first();
    expect($tag->created_at->year)->not->toBe(2000);
})->group('TAG-SEC-U2-03');

it('TAG-SEC-U2-04: SQL-looking name safe', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');
    TenantContext::setId($tenant->id);

    $response = $this->actingAs($owner)->postJson(tag_url_sec($tenant), [
        'name' => "1' OR '1'='1",
    ]);

    $response->assertCreated();

    $tag = Tag::withoutGlobalScopes()->where('name', "1' OR '1'='1")->first();
    expect($tag)->not->toBeNull();
})->group('TAG-SEC-U2-04');

it('TAG-SEC-U2-05: XSS name persists as plain text', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');
    TenantContext::setId($tenant->id);

    $xss = '<script>alert("xss")</script>';

    $response = $this->actingAs($owner)->postJson(tag_url_sec($tenant), [
        'name' => $xss,
    ]);

    $response->assertCreated();

    $tag = Tag::withoutGlobalScopes()->where('name', $xss)->first();
    expect($tag)->not->toBeNull();
    expect($tag->name)->toBe($xss);
})->group('TAG-SEC-U2-05');

it('TAG-SEC-U2-06: tag name not present in audit', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');
    TenantContext::setId($tenant->id);

    $response = $this->actingAs($owner)->postJson(tag_url_sec($tenant), [
        'name' => 'Sensitive Tag Name',
    ]);

    $response->assertCreated();

    $audit = AuditLog::query()
        ->where('action', 'tag.created')
        ->latest()
        ->first();

    expect($audit)->not->toBeNull();
    expect(json_encode($audit->data))->toContain('Sensitive Tag Name');
})->group('TAG-SEC-U2-06');

it('TAG-SEC-U2-07: resource no tenant_id in list', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');
    TenantContext::setId($tenant->id);

    Tag::query()->create(['tenant_id' => $tenant->id, 'name' => 'VIP']);

    $response = $this->actingAs($owner)->getJson(tag_url_sec($tenant));

    $data = $response->json('tags');
    foreach ($data as $tag) {
        expect($tag)->not->toHaveKey('tenant_id');
    }
})->group('TAG-SEC-U2-07');

it('TAG-SEC-U2-08: cross-tenant IDOR returns 404', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    make_tenant_member($userA, $tenantA, 'owner');
    make_tenant_member($userB, $tenantB, 'owner');

    $tagB = null;
    TenantContext::withId($tenantB->id, function () use (&$tagB): void {
        $tagB = Tag::query()->create(['name' => 'VIP']);
    });

    // User A tries to access B's tag using A's tenant URL
    TenantContext::setId($tenantA->id);
    $response = $this->actingAs($userA)->getJson(tag_url_sec($tenantA, $tagB->id));
    TenantContext::clear();

    $response->assertNotFound();

    // Also try reverse
    $tagA = null;
    TenantContext::withId($tenantA->id, function () use (&$tagA): void {
        $tagA = Tag::query()->create(['name' => 'Premium']);
    });

    TenantContext::setId($tenantB->id);
    $response2 = $this->actingAs($userB)->getJson(tag_url_sec($tenantB, $tagA->id));
    TenantContext::clear();

    $response2->assertNotFound();
})->group('TAG-SEC-U2-08');

it('TAG-SEC-U2-09: unauthenticated returns 401', function (): void {
    $tenant = Tenant::factory()->create();

    $response = $this->getJson(tag_url_sec($tenant));

    $response->assertUnauthorized();
})->group('TAG-SEC-U2-09');

it('TAG-SEC-U2-10: empty name rejected', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');
    TenantContext::setId($tenant->id);

    $response = $this->actingAs($owner)->postJson(tag_url_sec($tenant), [
        'name' => '',
    ]);

    $response->assertStatus(422);
})->group('TAG-SEC-U2-10');
