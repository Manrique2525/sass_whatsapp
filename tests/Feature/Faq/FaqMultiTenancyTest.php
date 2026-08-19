<?php

declare(strict_types=1);

use App\Domain\Faq\Models\Faq;
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
| FAQ Multi-Tenancy Tests (FASE 18 U3)
|--------------------------------------------------------------------------
|
| FAQ-MT-U3-01..10 — Tenant isolation for FAQ API.
| Corren en SQLite :memory:.
|
*/

function faq_url_mt(Tenant $tenant, ?string $faqId = null): string
{
    $base = '/api/v1/tenants/'.$tenant->id.'/faqs';

    return $faqId !== null ? "{$base}/{$faqId}" : $base;
}

it('FAQ-MT-U3-01: tenant A list does not include B', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $userA = User::factory()->create();
    make_tenant_member($userA, $tenantA, 'owner');

    TenantContext::setId($tenantB->id);
    Faq::factory()->create(['tenant_id' => $tenantB->id]);

    TenantContext::setId($tenantA->id);
    $response = $this->actingAs($userA)->getJson(faq_url_mt($tenantA));

    $response->assertOk()->assertJsonPath('meta.total', 0);
})->group('FAQ-MT-U3-01');

it('FAQ-MT-U3-02: tenant A show B FAQ returns 404', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $userA = User::factory()->create();
    make_tenant_member($userA, $tenantA, 'owner');

    TenantContext::setId($tenantB->id);
    $faqB = Faq::factory()->create(['tenant_id' => $tenantB->id]);

    TenantContext::setId($tenantA->id);
    $response = $this->actingAs($userA)->getJson(faq_url_mt($tenantA, $faqB->id));

    $response->assertNotFound();
})->group('FAQ-MT-U3-02');

it('FAQ-MT-U3-03: tenant A update B FAQ returns 404', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $userA = User::factory()->create();
    make_tenant_member($userA, $tenantA, 'owner');

    TenantContext::setId($tenantB->id);
    $faqB = Faq::factory()->create(['tenant_id' => $tenantB->id]);

    TenantContext::setId($tenantA->id);
    $response = $this->actingAs($userA)->patchJson(faq_url_mt($tenantA, $faqB->id), [
        'answer' => 'hacked',
    ]);

    $response->assertNotFound();
})->group('FAQ-MT-U3-03');

it('FAQ-MT-U3-04: tenant A delete B FAQ returns 404', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $userA = User::factory()->create();
    make_tenant_member($userA, $tenantA, 'owner');

    TenantContext::setId($tenantB->id);
    $faqB = Faq::factory()->create(['tenant_id' => $tenantB->id]);

    TenantContext::setId($tenantA->id);
    $response = $this->actingAs($userA)->deleteJson(faq_url_mt($tenantA, $faqB->id));

    $response->assertNotFound();
})->group('FAQ-MT-U3-04');

it('FAQ-MT-U3-05: tenant_id in body is ignored', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    make_tenant_member($user, $tenant, 'owner');
    TenantContext::setId($tenant->id);

    $otherTenant = Tenant::factory()->create();

    $response = $this->actingAs($user)->postJson(faq_url_mt($tenant), [
        'question' => 'Test',
        'answer' => 'Answer',
        'tenant_id' => $otherTenant->id,
    ]);

    $response->assertCreated();

    $faq = Faq::withoutGlobalScopes()->where('question', 'Test')->first();
    expect($faq->tenant_id)->toBe($tenant->id);
})->group('FAQ-MT-U3-05');

it('FAQ-MT-U3-06: same question A/B allowed', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $userA = User::factory()->create();
    make_tenant_member($userA, $tenantA, 'owner');

    TenantContext::setId($tenantA->id);
    $responseA = $this->actingAs($userA)->postJson(faq_url_mt($tenantA), [
        'question' => 'Test question',
        'answer' => 'Answer A',
    ]);
    $responseA->assertCreated();

    $userB = User::factory()->create();
    make_tenant_member($userB, $tenantB, 'owner');

    TenantContext::setId($tenantB->id);
    $responseB = $this->actingAs($userB)->postJson(faq_url_mt($tenantB), [
        'question' => 'Test question',
        'answer' => 'Answer B',
    ]);
    $responseB->assertCreated();
})->group('FAQ-MT-U3-06');

it('FAQ-MT-U3-07: agent A cannot manage', function (): void {
    $tenant = Tenant::factory()->create();
    $agent = User::factory()->create();
    make_tenant_member($agent, $tenant, 'agent');
    TenantContext::setId($tenant->id);

    $response = $this->actingAs($agent)->postJson(faq_url_mt($tenant), [
        'question' => 'Test',
        'answer' => 'Answer',
    ]);

    $response->assertStatus(403);
})->group('FAQ-MT-U3-07');

it('FAQ-MT-U3-08: agent A can view', function (): void {
    $tenant = Tenant::factory()->create();
    $agent = User::factory()->create();
    make_tenant_member($agent, $tenant, 'agent');
    TenantContext::setId($tenant->id);

    $response = $this->actingAs($agent)->getJson(faq_url_mt($tenant));

    $response->assertOk();
})->group('FAQ-MT-U3-08');

it('FAQ-MT-U3-09: inactive membership rejected', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    $user->tenants()->attach($tenant, [
        'role' => 'owner',
        'status' => 'inactive',
        'joined_at' => now(),
    ]);

    $response = $this->actingAs($user)->getJson(faq_url_mt($tenant));

    $response->assertStatus(403);
})->group('FAQ-MT-U3-09');

it('FAQ-MT-U3-10: malformed UUID no oracle', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    make_tenant_member($user, $tenant, 'owner');
    TenantContext::setId($tenant->id);

    $response = $this->actingAs($user)->getJson(faq_url_mt($tenant, 'not-a-valid-uuid'));

    $response->assertNotFound();
})->group('FAQ-MT-U3-10');
