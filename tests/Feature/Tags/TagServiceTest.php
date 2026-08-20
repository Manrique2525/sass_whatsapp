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
| FASE 20 U1 — TagService: invariants, idempotency, multi-tenancy
|--------------------------------------------------------------------------
*/

function create_test_tag(Tenant $tenant, string $name): Tag
{
    return TenantContext::withId($tenant->id, fn () => Tag::query()->create(['name' => $name]));
}

function create_test_contact(Tenant $tenant, string $phone = '+529931000001'): Contact
{
    return TenantContext::withId($tenant->id, fn () => Contact::query()->create([
        'name' => 'Test Contact',
        'phone' => $phone,
    ]));
}

test('TAG-U1-01: create new tag', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    try {
        $service = app(TagService::class);
        $tag = $service->findOrCreateByName($tenant, 'VIP');

        $this->assertDatabaseHas('tags', [
            'tenant_id' => $tenant->id,
            'name' => 'VIP',
        ]);
        expect($tag->name)->toBe('VIP');
        expect($tag->tenant_id)->toBe($tenant->id);
    } finally {
        TenantContext::clear();
    }
});

test('TAG-U1-02: find existing tag', function (): void {
    $tenant = Tenant::factory()->create();
    $existing = create_test_tag($tenant, 'VIP');

    TenantContext::setId($tenant->id);

    try {
        $service = app(TagService::class);
        $found = $service->findOrCreateByName($tenant, 'VIP');

        expect($found->id)->toBe($existing->id);
        $this->assertDatabaseCount('tags', 1);
    } finally {
        TenantContext::clear();
    }
});

test('TAG-U1-03: trim name', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    try {
        $service = app(TagService::class);
        $tag = $service->findOrCreateByName($tenant, '  VIP  ');

        expect($tag->name)->toBe('VIP');
    } finally {
        TenantContext::clear();
    }
});

test('TAG-U1-04: empty name throws exception', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    try {
        $service = app(TagService::class);
        $service->findOrCreateByName($tenant, '   ');
    } finally {
        TenantContext::clear();
    }
})->throws(InvalidArgumentException::class);

test('TAG-U1-05: same name same tenant returns same tag', function (): void {
    $tenant = Tenant::factory()->create();
    create_test_tag($tenant, 'VIP');

    TenantContext::setId($tenant->id);

    try {
        $service = app(TagService::class);
        $first = $service->findOrCreateByName($tenant, 'VIP');
        $second = $service->findOrCreateByName($tenant, 'VIP');

        expect($first->id)->toBe($second->id);
        $this->assertDatabaseCount('tags', 1);
    } finally {
        TenantContext::clear();
    }
});

test('TAG-U1-06: same name different tenant creates two tags', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    TenantContext::setId($tenantA->id);
    try {
        app(TagService::class)->findOrCreateByName($tenantA, 'VIP');
    } finally {
        TenantContext::clear();
    }

    TenantContext::setId($tenantB->id);
    try {
        app(TagService::class)->findOrCreateByName($tenantB, 'VIP');
    } finally {
        TenantContext::clear();
    }

    $this->assertDatabaseCount('tags', 2);
});

test('TAG-U1-07: tenant_id not mass assignable', function (): void {
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();

    TenantContext::setId($tenant->id);
    try {
        $tag = Tag::query()->create(['name' => 'Test', 'tenant_id' => $otherTenant->id]);
        expect($tag->tenant_id)->toBe($tenant->id);
    } finally {
        TenantContext::clear();
    }
});

test('TAG-U1-08: assign new tag to contact returns true', function (): void {
    $tenant = Tenant::factory()->create();
    $tag = create_test_tag($tenant, 'VIP');
    $contact = create_test_contact($tenant);

    TenantContext::setId($tenant->id);

    try {
        $service = app(TagService::class);
        $result = $service->assignToContact($contact, $tag);

        expect($result)->toBeTrue();
        $this->assertDatabaseHas('contact_tag', [
            'contact_id' => $contact->id,
            'tag_id' => $tag->id,
        ]);
    } finally {
        TenantContext::clear();
    }
});

test('TAG-U1-09: assign duplicate tag returns false', function (): void {
    $tenant = Tenant::factory()->create();
    $tag = create_test_tag($tenant, 'VIP');
    $contact = create_test_contact($tenant);

    TenantContext::setId($tenant->id);

    try {
        $service = app(TagService::class);
        $first = $service->assignToContact($contact, $tag);
        $second = $service->assignToContact($contact, $tag);

        expect($first)->toBeTrue();
        expect($second)->toBeFalse();
        $this->assertDatabaseCount('contact_tag', 1);
    } finally {
        TenantContext::clear();
    }
});

test('TAG-U1-10: remove assigned tag returns true', function (): void {
    $tenant = Tenant::factory()->create();
    $tag = create_test_tag($tenant, 'VIP');
    $contact = create_test_contact($tenant);

    TenantContext::setId($tenant->id);

    try {
        $service = app(TagService::class);
        $service->assignToContact($contact, $tag);
        $removed = $service->removeFromContact($contact, $tag);

        expect($removed)->toBeTrue();
        $this->assertDatabaseMissing('contact_tag', [
            'contact_id' => $contact->id,
            'tag_id' => $tag->id,
        ]);
    } finally {
        TenantContext::clear();
    }
});

test('TAG-U1-11: remove missing tag returns false', function (): void {
    $tenant = Tenant::factory()->create();
    $tag = create_test_tag($tenant, 'VIP');
    $contact = create_test_contact($tenant);

    TenantContext::setId($tenant->id);

    try {
        $service = app(TagService::class);
        $removed = $service->removeFromContact($contact, $tag);

        expect($removed)->toBeFalse();
    } finally {
        TenantContext::clear();
    }
});

test('TAG-U1-12: cross-tenant assignment rejected', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $tagA = create_test_tag($tenantA, 'VIP');
    $contactB = create_test_contact($tenantB, '+529932000001');

    TenantContext::setId($tenantB->id);

    try {
        $service = app(TagService::class);
        $service->assignToContact($contactB, $tagA);
        $this->fail('Expected RuntimeException for cross-tenant assignment');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('Cross-tenant');
    } finally {
        TenantContext::clear();
    }
});

test('TAG-U1-13: cross-tenant removal rejected', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $tagA = create_test_tag($tenantA, 'VIP');
    $contactB = create_test_contact($tenantB, '+529933000001');

    TenantContext::setId($tenantB->id);

    try {
        $service = app(TagService::class);
        $service->removeFromContact($contactB, $tagA);
        $this->fail('Expected RuntimeException for cross-tenant removal');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('Cross-tenant');
    } finally {
        TenantContext::clear();
    }
});

test('TAG-U1-14: TagFactory works', function (): void {
    $tenant = Tenant::factory()->create();

    TenantContext::setId($tenant->id);
    try {
        $tag = Tag::factory()->create();

        $this->assertDatabaseHas('tags', ['id' => $tag->id]);
        expect($tag->name)->not->toBeEmpty();
        expect($tag->tenant_id)->toBe($tenant->id);
    } finally {
        TenantContext::clear();
    }
});

test('TAG-U1-15: duplicate names in config creates one tag and one pivot', function (): void {
    $tenant = Tenant::factory()->create();
    $contact = create_test_contact($tenant);

    TenantContext::setId($tenant->id);

    try {
        $service = app(TagService::class);
        $tag = $service->findOrCreateByName($tenant, 'VIP');
        $service->assignToContact($contact, $tag);
        $service->assignToContact($contact, $tag);

        $this->assertDatabaseCount('tags', 1);
        $this->assertDatabaseCount('contact_tag', 1);
    } finally {
        TenantContext::clear();
    }
});
