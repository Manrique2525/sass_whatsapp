<?php

declare(strict_types=1);

use App\Application\Contacts\Services\TagService;
use App\Domain\Contacts\Enums\TagAssignmentOrigin;
use App\Domain\Contacts\Events\TagAssigned;
use App\Domain\Contacts\Events\TagRemoved;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Contacts\Models\Tag;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| TagService U3 Tests (FASE 20 U3) — Assignment + Events
|--------------------------------------------------------------------------
|
| TAG-U3-01..10 — Batch assign, remove, idempotency, events, batch atomicity.
| Corren en SQLite :memory:.
|
*/

afterEach(function (): void {
    TenantContext::clear();
});

function u3_tenant(): Tenant
{
    return Tenant::factory()->create();
}

function u3_owner(Tenant $tenant): User
{
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    return $owner;
}

function u3_contact(Tenant $tenant, string $phone = '+529980000001'): Contact
{
    return TenantContext::withId($tenant->id, fn () => Contact::query()->create([
        'name' => 'U3 Contact',
        'phone' => $phone,
    ]));
}

function u3_tag(Tenant $tenant, string $name): Tag
{
    return TenantContext::withId($tenant->id, fn () => Tag::query()->create(['name' => $name]));
}

it('TAG-U3-01: batch assign emits TagAssigned for each new tag', function (): void {
    $tenant = u3_tenant();
    $owner = u3_owner($tenant);
    TenantContext::setId($tenant->id);

    $contact = u3_contact($tenant, '+529980000002');
    $tag1 = u3_tag($tenant, 'VIP');
    $tag2 = u3_tag($tenant, 'Lead Caliente');

    Event::fake([TagAssigned::class]);

    $service = app(TagService::class);
    $result = $service->assignTagsToContact($owner, $tenant, $contact->id, [$tag1->id, $tag2->id]);

    Event::assertDispatched(TagAssigned::class, 2);
    Event::assertDispatched(TagAssigned::class, fn (TagAssigned $e) => $e->tagId === $tag1->id && $e->origin === TagAssignmentOrigin::Manual
    );
    Event::assertDispatched(TagAssigned::class, fn (TagAssigned $e) => $e->tagId === $tag2->id && $e->origin === TagAssignmentOrigin::Manual
    );

    expect($result->tags->count())->toBe(2);
})->group('TAG-U3-01');

it('TAG-U3-02: batch assign idempotent — no event for already assigned tag', function (): void {
    $tenant = u3_tenant();
    $owner = u3_owner($tenant);
    TenantContext::setId($tenant->id);

    $contact = u3_contact($tenant, '+529980000003');
    $tag = u3_tag($tenant, 'VIP');
    $contact->tags()->attach($tag->id);

    Event::fake([TagAssigned::class]);

    $service = app(TagService::class);
    $result = $service->assignTagsToContact($owner, $tenant, $contact->id, [$tag->id]);

    Event::assertNotDispatched(TagAssigned::class);
    expect($result->tags->count())->toBe(1);
})->group('TAG-U3-02');

it('TAG-U3-03: batch assign is atomic — invalid tag blocks all', function (): void {
    $tenant = u3_tenant();
    $owner = u3_owner($tenant);
    TenantContext::setId($tenant->id);

    $contact = u3_contact($tenant, '+529980000004');
    $validTag = u3_tag($tenant, 'VIP');
    $fakeId = Str::uuid()->toString();

    Event::fake([TagAssigned::class]);

    $service = app(TagService::class);

    try {
        $service->assignTagsToContact($owner, $tenant, $contact->id, [$validTag->id, $fakeId]);
    } catch (DomainException) {
        // Expected
    }

    expect($contact->fresh()->tags()->count())->toBe(0);
    Event::assertNotDispatched(TagAssigned::class);
})->group('TAG-U3-03');

it('TAG-U3-04: removeTagFromContact emits TagRemoved on real removal', function (): void {
    $tenant = u3_tenant();
    $owner = u3_owner($tenant);
    TenantContext::setId($tenant->id);

    $contact = u3_contact($tenant, '+529980000005');
    $tag = u3_tag($tenant, 'VIP');
    $contact->tags()->attach($tag->id);

    Event::fake([TagRemoved::class]);

    $service = app(TagService::class);
    $result = $service->removeTagFromContact($owner, $tenant, $contact->id, $tag->id);

    expect($result)->toBeTrue();
    Event::assertDispatched(TagRemoved::class, fn (TagRemoved $e) => $e->tagId === $tag->id && $e->tenantId === $tenant->id
    );
})->group('TAG-U3-04');

it('TAG-U3-05: removeTagFromContact no-event when not assigned (idempotent)', function (): void {
    $tenant = u3_tenant();
    $owner = u3_owner($tenant);
    TenantContext::setId($tenant->id);

    $contact = u3_contact($tenant, '+529980000006');
    $tag = u3_tag($tenant, 'VIP');

    Event::fake([TagRemoved::class]);

    $service = app(TagService::class);
    $result = $service->removeTagFromContact($owner, $tenant, $contact->id, $tag->id);

    expect($result)->toBeFalse();
    Event::assertNotDispatched(TagRemoved::class);
})->group('TAG-U3-05');

it('TAG-U3-06: batch assign returns contact with loaded tags', function (): void {
    $tenant = u3_tenant();
    $owner = u3_owner($tenant);
    TenantContext::setId($tenant->id);

    $contact = u3_contact($tenant, '+529980000007');
    $tag1 = u3_tag($tenant, 'VIP');
    $tag2 = u3_tag($tenant, 'Lead');

    $service = app(TagService::class);
    $result = $service->assignTagsToContact($owner, $tenant, $contact->id, [$tag1->id, $tag2->id]);

    expect($result->relationLoaded('tags'))->toBeTrue();
    expect($result->tags->pluck('name')->sort()->values()->toArray())->toBe(['Lead', 'VIP']);
})->group('TAG-U3-06');

it('TAG-U3-07: removeTagFromContact audits removal', function (): void {
    $tenant = u3_tenant();
    $owner = u3_owner($tenant);
    TenantContext::setId($tenant->id);

    $contact = u3_contact($tenant, '+529980000008');
    $tag = u3_tag($tenant, 'VIP');
    $contact->tags()->attach($tag->id);

    $service = app(TagService::class);
    $service->removeTagFromContact($owner, $tenant, $contact->id, $tag->id);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'tag.removed',
    ]);
})->group('TAG-U3-07');

it('TAG-U3-08: assignTagsToContact with empty tag_ids does nothing', function (): void {
    $tenant = u3_tenant();
    $owner = u3_owner($tenant);
    TenantContext::setId($tenant->id);

    $contact = u3_contact($tenant, '+529980000009');

    $service = app(TagService::class);
    $result = $service->assignTagsToContact($owner, $tenant, $contact->id, []);

    expect($result->tags->count())->toBe(0);
})->group('TAG-U3-08');

it('TAG-U3-09: batch assign audits each new assignment', function (): void {
    $tenant = u3_tenant();
    $owner = u3_owner($tenant);
    TenantContext::setId($tenant->id);

    $contact = u3_contact($tenant, '+529980000010');
    $tag1 = u3_tag($tenant, 'VIP');
    $tag2 = u3_tag($tenant, 'Lead');

    $service = app(TagService::class);
    $service->assignTagsToContact($owner, $tenant, $contact->id, [$tag1->id, $tag2->id]);

    $this->assertDatabaseHas('audit_logs', ['action' => 'tag.assigned']);
    $count = DB::table('audit_logs')
        ->where('action', 'tag.assigned')
        ->count();
    expect($count)->toBe(2);
})->group('TAG-U3-09');

it('TAG-U3-10: removeTagFromContact returns false for nonexistent tag', function (): void {
    $tenant = u3_tenant();
    $owner = u3_owner($tenant);
    TenantContext::setId($tenant->id);

    $contact = u3_contact($tenant, '+529980000011');

    $service = app(TagService::class);

    try {
        $service->removeTagFromContact($owner, $tenant, $contact->id, Str::uuid()->toString());
    } catch (DomainException) {
        $this->assertTrue(true);

        return;
    }

    $this->fail('Expected DomainException was not thrown.');
})->group('TAG-U3-10');
