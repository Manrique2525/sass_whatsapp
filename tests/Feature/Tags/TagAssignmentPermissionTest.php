<?php

declare(strict_types=1);

use App\Domain\Contacts\Models\Contact;
use App\Domain\Contacts\Models\Tag;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Tag Assignment Permission Tests (FASE 20 U3)
|--------------------------------------------------------------------------
|
| TAG-ASG-PERM-01..06 — Permission matrix for tags.manage (assign/remove).
| Agent role (ViewTags only) cannot assign or remove.
| Corren en SQLite :memory:.
|
*/

afterEach(function (): void {
    TenantContext::clear();
});

function asg_perm_url(Tenant $tenant, string $contactId, ?string $tagId = null): string
{
    $base = '/api/v1/tenants/'.$tenant->id.'/contacts/'.$contactId.'/tags';

    return $tagId !== null ? "{$base}/{$tagId}" : $base;
}

it('TAG-ASG-PERM-01: owner can assign tags', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');
    TenantContext::setId($tenant->id);

    $contact = TenantContext::withId($tenant->id, fn () => Contact::query()->create([
        'name' => 'Perm Contact', 'phone' => '+529960000001',
    ]));
    $tag = TenantContext::withId($tenant->id, fn () => Tag::query()->create(['name' => 'VIP']));

    $this->actingAs($owner)->postJson(asg_perm_url($tenant, $contact->id), [
        'tag_ids' => [$tag->id],
    ])->assertOk();
})->group('TAG-ASG-PERM-01');

it('TAG-ASG-PERM-02: admin can assign tags', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = User::factory()->create();
    make_tenant_member($admin, $tenant, 'admin');
    TenantContext::setId($tenant->id);

    $contact = TenantContext::withId($tenant->id, fn () => Contact::query()->create([
        'name' => 'Perm Contact', 'phone' => '+529960000002',
    ]));
    $tag = TenantContext::withId($tenant->id, fn () => Tag::query()->create(['name' => 'VIP']));

    $this->actingAs($admin)->postJson(asg_perm_url($tenant, $contact->id), [
        'tag_ids' => [$tag->id],
    ])->assertOk();
})->group('TAG-ASG-PERM-02');

it('TAG-ASG-PERM-03: agent cannot assign tags (403)', function (): void {
    $tenant = Tenant::factory()->create();
    $agent = User::factory()->create();
    make_tenant_member($agent, $tenant, 'agent');
    TenantContext::setId($tenant->id);

    $contact = TenantContext::withId($tenant->id, fn () => Contact::query()->create([
        'name' => 'Perm Contact', 'phone' => '+529960000003',
    ]));
    $tag = TenantContext::withId($tenant->id, fn () => Tag::query()->create(['name' => 'VIP']));

    $this->actingAs($agent)->postJson(asg_perm_url($tenant, $contact->id), [
        'tag_ids' => [$tag->id],
    ])->assertForbidden();
})->group('TAG-ASG-PERM-03');

it('TAG-ASG-PERM-04: agent cannot remove tags (403)', function (): void {
    $tenant = Tenant::factory()->create();
    $agent = User::factory()->create();
    make_tenant_member($agent, $tenant, 'agent');
    TenantContext::setId($tenant->id);

    $contact = TenantContext::withId($tenant->id, fn () => Contact::query()->create([
        'name' => 'Perm Contact', 'phone' => '+529960000004',
    ]));
    $tag = TenantContext::withId($tenant->id, fn () => Tag::query()->create(['name' => 'VIP']));

    $this->actingAs($agent)->deleteJson(asg_perm_url($tenant, $contact->id, $tag->id))
        ->assertForbidden();
})->group('TAG-ASG-PERM-04');

it('TAG-ASG-PERM-05: owner can remove tags', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');
    TenantContext::setId($tenant->id);

    $contact = TenantContext::withId($tenant->id, fn () => Contact::query()->create([
        'name' => 'Perm Contact', 'phone' => '+529960000005',
    ]));
    $tag = TenantContext::withId($tenant->id, fn () => Tag::query()->create(['name' => 'VIP']));
    $contact->tags()->attach($tag->id);

    $this->actingAs($owner)->deleteJson(asg_perm_url($tenant, $contact->id, $tag->id))
        ->assertOk();
})->group('TAG-ASG-PERM-05');

it('TAG-ASG-PERM-06: admin can remove tags', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = User::factory()->create();
    make_tenant_member($admin, $tenant, 'admin');
    TenantContext::setId($tenant->id);

    $contact = TenantContext::withId($tenant->id, fn () => Contact::query()->create([
        'name' => 'Perm Contact', 'phone' => '+529960000006',
    ]));
    $tag = TenantContext::withId($tenant->id, fn () => Tag::query()->create(['name' => 'VIP']));
    $contact->tags()->attach($tag->id);

    $this->actingAs($admin)->deleteJson(asg_perm_url($tenant, $contact->id, $tag->id))
        ->assertOk();
})->group('TAG-ASG-PERM-06');
