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
| FAQ Permission Tests (FASE 18 U3)
|--------------------------------------------------------------------------
|
| FAQ-PERM-01..06 — Permission matrix for faqs.view / faqs.manage.
| Corren en SQLite :memory:.
|
*/

function faq_url_p(Tenant $tenant, ?string $faqId = null): string
{
    $base = '/api/v1/tenants/'.$tenant->id.'/faqs';

    return $faqId !== null ? "{$base}/{$faqId}" : $base;
}

it('FAQ-PERM-01: owner has view and manage', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');
    TenantContext::setId($tenant->id);

    $list = $this->actingAs($owner)->getJson(faq_url_p($tenant));
    $list->assertOk();

    $create = $this->actingAs($owner)->postJson(faq_url_p($tenant), [
        'question' => 'Test',
        'answer' => 'Answer',
    ]);
    $create->assertCreated();
})->group('FAQ-PERM-01');

it('FAQ-PERM-02: admin has view and manage', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = User::factory()->create();
    make_tenant_member($admin, $tenant, 'admin');
    TenantContext::setId($tenant->id);

    $list = $this->actingAs($admin)->getJson(faq_url_p($tenant));
    $list->assertOk();

    $create = $this->actingAs($admin)->postJson(faq_url_p($tenant), [
        'question' => 'Test',
        'answer' => 'Answer',
    ]);
    $create->assertCreated();
})->group('FAQ-PERM-02');

it('FAQ-PERM-03: agent has view', function (): void {
    $tenant = Tenant::factory()->create();
    $agent = User::factory()->create();
    make_tenant_member($agent, $tenant, 'agent');
    TenantContext::setId($tenant->id);

    $list = $this->actingAs($agent)->getJson(faq_url_p($tenant));
    $list->assertOk();
})->group('FAQ-PERM-03');

it('FAQ-PERM-04: agent cannot create (403)', function (): void {
    $tenant = Tenant::factory()->create();
    $agent = User::factory()->create();
    make_tenant_member($agent, $tenant, 'agent');
    TenantContext::setId($tenant->id);

    $response = $this->actingAs($agent)->postJson(faq_url_p($tenant), [
        'question' => 'Test',
        'answer' => 'Answer',
    ]);

    $response->assertStatus(403)->assertJson([
        'code' => 'PERMISSION_DENIED',
    ]);
})->group('FAQ-PERM-04');

it('FAQ-PERM-05: agent cannot update (403)', function (): void {
    $tenant = Tenant::factory()->create();
    $agent = User::factory()->create();
    make_tenant_member($agent, $tenant, 'agent');
    TenantContext::setId($tenant->id);

    $faq = Faq::factory()->create([
        'tenant_id' => $tenant->id,
        'question' => 'Test',
        'normalized_question' => 'test',
    ]);

    $response = $this->actingAs($agent)->patchJson(faq_url_p($tenant, $faq->id), [
        'answer' => 'hacked',
    ]);

    $response->assertStatus(403);
})->group('FAQ-PERM-05');

it('FAQ-PERM-06: agent cannot delete (403)', function (): void {
    $tenant = Tenant::factory()->create();
    $agent = User::factory()->create();
    make_tenant_member($agent, $tenant, 'agent');
    TenantContext::setId($tenant->id);

    $faq = Faq::factory()->create([
        'tenant_id' => $tenant->id,
        'question' => 'Test',
        'normalized_question' => 'test',
    ]);

    $response = $this->actingAs($agent)->deleteJson(faq_url_p($tenant, $faq->id));

    $response->assertStatus(403);
})->group('FAQ-PERM-06');
