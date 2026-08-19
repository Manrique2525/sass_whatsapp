<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
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
| FAQ Security Tests (FASE 18 U3)
|--------------------------------------------------------------------------
|
| FAQ-SEC-U3-01..10 — Injection, mass assignment, audit safety.
| Corren en SQLite :memory:.
|
*/

function faq_url_sec(Tenant $tenant, ?string $faqId = null): string
{
    $base = '/api/v1/tenants/'.$tenant->id.'/faqs';

    return $faqId !== null ? "{$base}/{$faqId}" : $base;
}

it('FAQ-SEC-U3-01: tenant_id injection ignored', function (): void {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');
    TenantContext::setId($tenant->id);

    $response = $this->actingAs($owner)->postJson(faq_url_sec($tenant), [
        'question' => 'Test',
        'answer' => 'Answer',
        'tenant_id' => $other->id,
    ]);

    $response->assertCreated();

    $faq = Faq::withoutGlobalScopes()->where('question', 'Test')->first();
    expect($faq->tenant_id)->toBe($tenant->id);
})->group('FAQ-SEC-U3-01');

it('FAQ-SEC-U3-02: normalized_question injection ignored', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');
    TenantContext::setId($tenant->id);

    $response = $this->actingAs($owner)->postJson(faq_url_sec($tenant), [
        'question' => 'Test question',
        'answer' => 'Answer',
        'normalized_question' => 'injected',
    ]);

    $response->assertCreated();

    $faq = Faq::withoutGlobalScopes()->where('question', 'Test question')->first();
    expect($faq->normalized_question)->not->toBe('injected');
})->group('FAQ-SEC-U3-02');

it('FAQ-SEC-U3-03: mass assignment timestamps protected', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');
    TenantContext::setId($tenant->id);

    $response = $this->actingAs($owner)->postJson(faq_url_sec($tenant), [
        'question' => 'Test',
        'answer' => 'Answer',
        'created_at' => '2000-01-01',
        'updated_at' => '2000-01-01',
    ]);

    $response->assertCreated();

    $faq = Faq::withoutGlobalScopes()->where('question', 'Test')->first();
    expect($faq->created_at->year)->not->toBe(2000);
})->group('FAQ-SEC-U3-03');

it('FAQ-SEC-U3-04: HTML answer persists as plain text', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');
    TenantContext::setId($tenant->id);

    $html = '<script>alert("xss")</script>';

    $response = $this->actingAs($owner)->postJson(faq_url_sec($tenant), [
        'question' => 'Test',
        'answer' => $html,
    ]);

    $response->assertCreated();

    $faq = Faq::withoutGlobalScopes()->where('question', 'Test')->first();
    expect($faq->answer)->toBe($html);
})->group('FAQ-SEC-U3-04');

it('FAQ-SEC-U3-05: SQL-looking question safe', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');
    TenantContext::setId($tenant->id);

    $response = $this->actingAs($owner)->postJson(faq_url_sec($tenant), [
        'question' => "1' OR '1'='1",
        'answer' => 'Answer',
    ]);

    $response->assertCreated();

    $faq = Faq::withoutGlobalScopes()->where('question', "1' OR '1'='1")->first();
    expect($faq)->not->toBeNull();
})->group('FAQ-SEC-U3-05');

it('FAQ-SEC-U3-06: answer not present in audit', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');
    TenantContext::setId($tenant->id);

    $response = $this->actingAs($owner)->postJson(faq_url_sec($tenant), [
        'question' => 'Test',
        'answer' => 'Sensitive answer content',
    ]);

    $response->assertCreated();

    $audit = AuditLog::query()
        ->where('action', 'faq.created')
        ->latest()
        ->first();

    expect($audit)->not->toBeNull();
    expect(json_encode($audit->data))->not->toContain('Sensitive answer content');
})->group('FAQ-SEC-U3-06');

it('FAQ-SEC-U3-07: question not present in audit', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');
    TenantContext::setId($tenant->id);

    $response = $this->actingAs($owner)->postJson(faq_url_sec($tenant), [
        'question' => 'My exact question text',
        'answer' => 'Answer',
    ]);

    $response->assertCreated();

    $audit = AuditLog::query()
        ->where('action', 'faq.created')
        ->latest()
        ->first();

    expect(json_encode($audit->data))->not->toContain('My exact question text');
})->group('FAQ-SEC-U3-07');

it('FAQ-SEC-U3-08: resource no tenant_id', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');
    TenantContext::setId($tenant->id);

    $faq = Faq::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this->actingAs($owner)->getJson(faq_url_sec($tenant, $faq->id));

    $data = $response->json('faq');
    expect($data)->not->toHaveKey('tenant_id');
})->group('FAQ-SEC-U3-08');

it('FAQ-SEC-U3-09: resource no normalized_question', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');
    TenantContext::setId($tenant->id);

    $faq = Faq::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = $this->actingAs($owner)->getJson(faq_url_sec($tenant, $faq->id));

    $data = $response->json('faq');
    expect($data)->not->toHaveKey('normalized_question');
})->group('FAQ-SEC-U3-09');

it('FAQ-SEC-U3-10: cross-tenant IDOR returns 404', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    make_tenant_member($userA, $tenantA, 'owner');
    make_tenant_member($userB, $tenantB, 'owner');

    TenantContext::setId($tenantB->id);
    $faqB = Faq::factory()->create(['tenant_id' => $tenantB->id]);

    // User A tries to access B's FAQ using A's tenant URL
    TenantContext::setId($tenantA->id);
    $response = $this->actingAs($userA)->getJson(faq_url_sec($tenantA, $faqB->id));

    $response->assertNotFound();

    // Also try reverse: User B tries to access A's FAQ using B's URL
    TenantContext::setId($tenantA->id);
    $faqA = Faq::factory()->create(['tenant_id' => $tenantA->id]);

    TenantContext::setId($tenantB->id);
    $response2 = $this->actingAs($userB)->getJson(faq_url_sec($tenantB, $faqA->id));

    $response2->assertNotFound();
})->group('FAQ-SEC-U3-10');
