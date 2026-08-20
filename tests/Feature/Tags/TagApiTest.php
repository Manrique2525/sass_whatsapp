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
| Tag API Tests (FASE 20 U2)
|--------------------------------------------------------------------------
|
| TAG-API-01..15 — CRUD API, search, pagination, validation, resource.
| Corren en SQLite :memory:.
|
*/

function tag_url(Tenant $tenant, ?string $tagId = null): string
{
    $base = '/api/v1/tenants/'.$tenant->id.'/tags';

    return $tagId !== null ? "{$base}/{$tagId}" : $base;
}

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->owner = User::factory()->create();
    make_tenant_member($this->owner, $this->tenant, 'owner');
    TenantContext::setId($this->tenant->id);
});

it('TAG-API-01: create returns 201 and persists', function (): void {
    $response = $this->actingAs($this->owner)->postJson(tag_url($this->tenant), [
        'name' => 'VIP',
    ]);

    $response->assertCreated()->assertJson([
        'message' => 'Tag creado.',
        'tag' => [
            'name' => 'VIP',
        ],
    ]);

    expect(Tag::withoutGlobalScopes()->count())->toBe(1);
})->group('TAG-API-01');

it('TAG-API-02: list returns tags', function (): void {
    TenantContext::withId($this->tenant->id, function (): void {
        Tag::query()->create(['name' => 'VIP']);
        Tag::query()->create(['name' => 'Premium']);
        Tag::query()->create(['name' => 'Lead']);
    });

    $response = $this->actingAs($this->owner)->getJson(tag_url($this->tenant));

    $response->assertOk()->assertJsonStructure([
        'tags' => [['id', 'name', 'created_at', 'updated_at']],
        'meta' => ['current_page', 'last_page', 'per_page', 'total'],
    ]);

    $response->assertJsonPath('meta.total', 3);
})->group('TAG-API-02');

it('TAG-API-03: pagination works', function (): void {
    TenantContext::withId($this->tenant->id, function (): void {
        for ($i = 0; $i < 25; $i++) {
            Tag::query()->create(['name' => "Tag {$i}"]);
        }
    });

    $response = $this->actingAs($this->owner)->getJson(tag_url($this->tenant).'?per_page=10');

    $response->assertOk();
    $response->assertJsonPath('meta.per_page', 10);
    $response->assertJsonPath('meta.total', 25);
    expect($response->json('tags'))->toHaveCount(10);
})->group('TAG-API-03');

it('TAG-API-04: search filters by name', function (): void {
    TenantContext::withId($this->tenant->id, function (): void {
        Tag::query()->create(['name' => 'VIP']);
        Tag::query()->create(['name' => 'Premium']);
    });

    $response = $this->actingAs($this->owner)->getJson(tag_url($this->tenant).'?search=VIP');

    $response->assertOk();
    $response->assertJsonPath('meta.total', 1);
})->group('TAG-API-04');

it('TAG-API-05: show returns tag', function (): void {
    $tag = null;
    TenantContext::withId($this->tenant->id, function () use (&$tag): void {
        $tag = Tag::query()->create(['name' => 'VIP']);
    });

    $response = $this->actingAs($this->owner)->getJson(tag_url($this->tenant, $tag->id));

    $response->assertOk()->assertJson([
        'tag' => [
            'id' => $tag->id,
            'name' => 'VIP',
        ],
    ]);
})->group('TAG-API-05');

it('TAG-API-06: show nonexistent returns 404', function (): void {
    $response = $this->actingAs($this->owner)->getJson(tag_url($this->tenant, '00000000-0000-0000-0000-000000000000'));

    $response->assertNotFound();
})->group('TAG-API-06');

it('TAG-API-07: update name', function (): void {
    $tag = null;
    TenantContext::withId($this->tenant->id, function () use (&$tag): void {
        $tag = Tag::query()->create(['name' => 'VIP']);
    });

    $response = $this->actingAs($this->owner)->patchJson(tag_url($this->tenant, $tag->id), [
        'name' => 'VIP Plus',
    ]);

    $response->assertOk();

    $tag->refresh();
    expect($tag->name)->toBe('VIP Plus');
})->group('TAG-API-07');

it('TAG-API-08: update to duplicate name returns 409', function (): void {
    $tag2 = null;
    TenantContext::withId($this->tenant->id, function () use (&$tag2): void {
        Tag::query()->create(['name' => 'VIP']);
        $tag2 = Tag::query()->create(['name' => 'Premium']);
    });

    $response = $this->actingAs($this->owner)->patchJson(tag_url($this->tenant, $tag2->id), [
        'name' => 'VIP',
    ]);

    $response->assertStatus(409)->assertJson([
        'code' => 'TAG_DUPLICATE',
    ]);
})->group('TAG-API-08');

it('TAG-API-09: update same name is no-op', function (): void {
    $tag = null;
    TenantContext::withId($this->tenant->id, function () use (&$tag): void {
        $tag = Tag::query()->create(['name' => 'VIP']);
    });

    $response = $this->actingAs($this->owner)->patchJson(tag_url($this->tenant, $tag->id), [
        'name' => 'VIP',
    ]);

    $response->assertOk();
})->group('TAG-API-09');

it('TAG-API-10: delete returns 200', function (): void {
    $tag = null;
    TenantContext::withId($this->tenant->id, function () use (&$tag): void {
        $tag = Tag::query()->create(['name' => 'VIP']);
    });

    $response = $this->actingAs($this->owner)->deleteJson(tag_url($this->tenant, $tag->id));

    $response->assertOk()->assertJson(['message' => 'Tag eliminado.']);

    expect(Tag::withoutGlobalScopes()->find($tag->id))->toBeNull();
})->group('TAG-API-10');

it('TAG-API-11: deleted tag show returns 404', function (): void {
    $tag = null;
    TenantContext::withId($this->tenant->id, function () use (&$tag): void {
        $tag = Tag::query()->create(['name' => 'VIP']);
        $tag->delete();
    });

    $response = $this->actingAs($this->owner)->getJson(tag_url($this->tenant, $tag->id));

    $response->assertNotFound();
})->group('TAG-API-11');

it('TAG-API-12: duplicate create returns 409', function (): void {
    TenantContext::withId($this->tenant->id, function (): void {
        Tag::query()->create(['name' => 'VIP']);
    });

    $response = $this->actingAs($this->owner)->postJson(tag_url($this->tenant), [
        'name' => 'VIP',
    ]);

    $response->assertStatus(409)->assertJson([
        'code' => 'TAG_DUPLICATE',
    ]);
})->group('TAG-API-12');

it('TAG-API-13: missing name returns 422', function (): void {
    $response = $this->actingAs($this->owner)->postJson(tag_url($this->tenant), []);

    $response->assertStatus(422);
})->group('TAG-API-13');

it('TAG-API-14: name max length enforced', function (): void {
    $response = $this->actingAs($this->owner)->postJson(tag_url($this->tenant), [
        'name' => str_repeat('a', 101),
    ]);

    $response->assertStatus(422);
})->group('TAG-API-14');

it('TAG-API-15: resource hides tenant_id', function (): void {
    $tag = null;
    TenantContext::withId($this->tenant->id, function () use (&$tag): void {
        $tag = Tag::query()->create(['name' => 'VIP']);
    });

    $response = $this->actingAs($this->owner)->getJson(tag_url($this->tenant, $tag->id));

    $response->assertOk();

    $data = $response->json('tag');
    expect($data)->not->toHaveKey('tenant_id');
    expect($data)->toHaveKey('id');
    expect($data)->toHaveKey('name');
    expect($data)->toHaveKey('created_at');
    expect($data)->toHaveKey('updated_at');
})->group('TAG-API-15');
