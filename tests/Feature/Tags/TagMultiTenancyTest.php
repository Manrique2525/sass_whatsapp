<?php

declare(strict_types=1);

use App\Application\Contacts\Services\TagService;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Contacts\Models\Tag;
use App\Domain\Tenants\Models\Tenant;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| FASE 20 U1 — Multi-tenancy tag isolation tests
|--------------------------------------------------------------------------
*/

function create_mt_tag(Tenant $tenant, string $name): Tag
{
    return TenantContext::withId($tenant->id, fn () => Tag::query()->create(['name' => $name]));
}

function create_mt_contact(Tenant $tenant, string $phone): Contact
{
    return TenantContext::withId($tenant->id, fn () => Contact::query()->create([
        'name' => 'Test Contact',
        'phone' => $phone,
    ]));
}

test('TAG-MT-U1-01: tenant A creates tag A', function (): void {
    $tenantA = Tenant::factory()->create();
    $tag = create_mt_tag($tenantA, 'VIP');

    expect($tag->tenant_id)->toBe($tenantA->id);
    $this->assertDatabaseHas('tags', ['id' => $tag->id, 'tenant_id' => $tenantA->id]);
});

test('TAG-MT-U1-02: tenant B cannot see tenant A tags via service', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    create_mt_tag($tenantA, 'VIP');

    TenantContext::setId($tenantB->id);
    try {
        $tag = Tag::query()->where('name', 'VIP')->first();
        expect($tag)->toBeNull();
    } finally {
        TenantContext::clear();
    }
});

test('TAG-MT-U1-03: A tag cannot assign B contact', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $tagA = create_mt_tag($tenantA, 'VIP');
    $contactB = create_mt_contact($tenantB, '+529934000001');

    TenantContext::setId($tenantB->id);
    try {
        $service = app(TagService::class);
        $service->assignToContact($contactB, $tagA);
        $this->fail('Expected RuntimeException');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('Cross-tenant');
    } finally {
        TenantContext::clear();
    }
});

test('TAG-MT-U1-04: B tag cannot assign A contact', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $tagB = create_mt_tag($tenantB, 'VIP');
    $contactA = create_mt_contact($tenantA, '+529935000001');

    TenantContext::setId($tenantA->id);
    try {
        $service = app(TagService::class);
        $service->assignToContact($contactA, $tagB);
        $this->fail('Expected RuntimeException');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('Cross-tenant');
    } finally {
        TenantContext::clear();
    }
});

test('TAG-MT-U1-05: same tag name A and B are independent', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $tagA = create_mt_tag($tenantA, 'VIP');
    $tagB = create_mt_tag($tenantB, 'VIP');

    expect($tagA->id)->not->toBe($tagB->id);

    $contactA = create_mt_contact($tenantA, '+529936000001');
    $contactB = create_mt_contact($tenantB, '+529937000001');

    TenantContext::setId($tenantA->id);
    try {
        app(TagService::class)->assignToContact($contactA, $tagA);
    } finally {
        TenantContext::clear();
    }

    TenantContext::setId($tenantB->id);
    try {
        app(TagService::class)->assignToContact($contactB, $tagB);
    } finally {
        TenantContext::clear();
    }

    TenantContext::setId($tenantA->id);
    try {
        expect($contactA->fresh()->tags()->count())->toBe(1);
        expect($contactB->fresh()->tags()->count())->toBe(0);
    } finally {
        TenantContext::clear();
    }
});

test('TAG-MT-U1-06: TenantContext restored after service calls', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $tagA = create_mt_tag($tenantA, 'VIP');
    $contactA = create_mt_contact($tenantA, '+529938000001');

    TenantContext::setId($tenantA->id);
    try {
        app(TagService::class)->assignToContact($contactA, $tagA);

        expect(TenantContext::id())->toBe($tenantA->id);
    } finally {
        TenantContext::clear();
    }
});
