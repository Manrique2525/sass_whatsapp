<?php

declare(strict_types=1);

use App\Domain\Faq\Enums\FaqStatus;
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
| FAQ API Tests (FASE 18 U3)
|--------------------------------------------------------------------------
|
| FAQ-API-01..20 — CRUD API, search, pagination, validation, resource.
| Corren en SQLite :memory:.
|
*/

function faq_url(Tenant $tenant, ?string $faqId = null): string
{
    $base = '/api/v1/tenants/'.$tenant->id.'/faqs';

    return $faqId !== null ? "{$base}/{$faqId}" : $base;
}

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->owner = User::factory()->create();
    make_tenant_member($this->owner, $this->tenant, 'owner');
    TenantContext::setId($this->tenant->id);
});

it('FAQ-API-01: create returns 201 and persists', function (): void {
    $response = $this->actingAs($this->owner)->postJson(faq_url($this->tenant), [
        'question' => '¿Cuál es tu horario?',
        'answer' => 'Lunes a viernes de 9 a 18.',
        'priority' => 5,
        'status' => 'active',
    ]);

    $response->assertCreated()->assertJson([
        'message' => 'FAQ creada.',
        'faq' => [
            'question' => '¿Cuál es tu horario?',
            'answer' => 'Lunes a viernes de 9 a 18.',
            'status' => 'active',
            'priority' => 5,
        ],
    ]);

    expect(Faq::withoutGlobalScopes()->count())->toBe(1);
})->group('FAQ-API-01');

it('FAQ-API-02: list returns FAQs', function (): void {
    Faq::factory()->count(3)->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $response = $this->actingAs($this->owner)->getJson(faq_url($this->tenant));

    $response->assertOk()->assertJsonStructure([
        'faqs' => [['id', 'question', 'answer', 'status', 'priority']],
        'meta' => ['current_page', 'last_page', 'per_page', 'total'],
    ]);

    $response->assertJsonPath('meta.total', 3);
})->group('FAQ-API-02');

it('FAQ-API-03: pagination works', function (): void {
    Faq::factory()->count(25)->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $response = $this->actingAs($this->owner)->getJson(faq_url($this->tenant).'?per_page=10');

    $response->assertOk();
    $response->assertJsonPath('meta.per_page', 10);
    $response->assertJsonPath('meta.total', 25);
    expect($response->json('faqs'))->toHaveCount(10);
})->group('FAQ-API-03');

it('FAQ-API-04: search filters by question', function (): void {
    Faq::factory()->create([
        'tenant_id' => $this->tenant->id,
        'question' => 'Horario de atención',
    ]);
    Faq::factory()->create([
        'tenant_id' => $this->tenant->id,
        'question' => 'Política de devolución',
    ]);

    $response = $this->actingAs($this->owner)->getJson(faq_url($this->tenant).'?search=Horario');

    $response->assertOk();
    $response->assertJsonPath('meta.total', 1);
})->group('FAQ-API-04');

it('FAQ-API-05: status filter works', function (): void {
    Faq::factory()->create([
        'tenant_id' => $this->tenant->id,
        'status' => FaqStatus::Active,
    ]);
    Faq::factory()->create([
        'tenant_id' => $this->tenant->id,
        'status' => FaqStatus::Inactive,
    ]);

    $response = $this->actingAs($this->owner)->getJson(faq_url($this->tenant).'?status=active');

    $response->assertOk();
    $response->assertJsonPath('meta.total', 1);
})->group('FAQ-API-05');

it('FAQ-API-06: show returns FAQ', function (): void {
    $faq = Faq::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $response = $this->actingAs($this->owner)->getJson(faq_url($this->tenant, $faq->id));

    $response->assertOk()->assertJson([
        'faq' => [
            'id' => $faq->id,
            'question' => $faq->question,
        ],
    ]);
})->group('FAQ-API-06');

it('FAQ-API-07: update question recalculates normalized_question', function (): void {
    $faq = Faq::factory()->create([
        'tenant_id' => $this->tenant->id,
        'question' => 'Pregunta vieja',
        'normalized_question' => 'pregunta vieja',
    ]);

    $response = $this->actingAs($this->owner)->patchJson(faq_url($this->tenant, $faq->id), [
        'question' => 'Pregunta nueva',
    ]);

    $response->assertOk();

    $faq->refresh();
    expect($faq->normalized_question)->toBe('pregunta nueva');
})->group('FAQ-API-07');

it('FAQ-API-08: update answer only', function (): void {
    $faq = Faq::factory()->create([
        'tenant_id' => $this->tenant->id,
        'question' => 'Test',
        'normalized_question' => 'test',
        'answer' => 'Old answer',
    ]);

    $response = $this->actingAs($this->owner)->patchJson(faq_url($this->tenant, $faq->id), [
        'answer' => 'New answer',
    ]);

    $response->assertOk();

    $faq->refresh();
    expect($faq->answer)->toBe('New answer');
})->group('FAQ-API-08');

it('FAQ-API-09: update status', function (): void {
    $faq = Faq::factory()->create([
        'tenant_id' => $this->tenant->id,
        'status' => FaqStatus::Active,
    ]);

    $response = $this->actingAs($this->owner)->patchJson(faq_url($this->tenant, $faq->id), [
        'status' => 'inactive',
    ]);

    $response->assertOk();

    $faq->refresh();
    expect($faq->status)->toBe(FaqStatus::Inactive);
})->group('FAQ-API-09');

it('FAQ-API-10: update priority', function (): void {
    $faq = Faq::factory()->create([
        'tenant_id' => $this->tenant->id,
        'priority' => 0,
    ]);

    $response = $this->actingAs($this->owner)->patchJson(faq_url($this->tenant, $faq->id), [
        'priority' => 50,
    ]);

    $response->assertOk();

    $faq->refresh();
    expect($faq->priority)->toBe(50);
})->group('FAQ-API-10');

it('FAQ-API-11: soft delete', function (): void {
    $faq = Faq::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $response = $this->actingAs($this->owner)->deleteJson(faq_url($this->tenant, $faq->id));

    $response->assertOk()->assertJson(['message' => 'FAQ eliminada.']);

    expect(Faq::withoutGlobalScopes()->find($faq->id)->deleted_at)->not->toBeNull();
})->group('FAQ-API-11');

it('FAQ-API-12: deleted show returns 404', function (): void {
    $faq = Faq::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);
    $faq->delete();

    $response = $this->actingAs($this->owner)->getJson(faq_url($this->tenant, $faq->id));

    $response->assertNotFound();
})->group('FAQ-API-12');

it('FAQ-API-13: duplicate create returns 409', function (): void {
    Faq::factory()->create([
        'tenant_id' => $this->tenant->id,
        'normalized_question' => 'test',
        'status' => FaqStatus::Active,
    ]);

    $response = $this->actingAs($this->owner)->postJson(faq_url($this->tenant), [
        'question' => 'test',
        'answer' => 'answer',
    ]);

    $response->assertStatus(409)->assertJson([
        'code' => 'FAQ_DUPLICATE',
    ]);
})->group('FAQ-API-13');

it('FAQ-API-14: duplicate update returns 409', function (): void {
    Faq::factory()->create([
        'tenant_id' => $this->tenant->id,
        'normalized_question' => 'pregunta uno',
        'status' => FaqStatus::Active,
    ]);
    $faq2 = Faq::factory()->create([
        'tenant_id' => $this->tenant->id,
        'normalized_question' => 'pregunta dos',
        'status' => FaqStatus::Active,
    ]);

    $response = $this->actingAs($this->owner)->patchJson(faq_url($this->tenant, $faq2->id), [
        'question' => 'pregunta uno',
    ]);

    $response->assertStatus(409)->assertJson([
        'code' => 'FAQ_DUPLICATE',
    ]);
})->group('FAQ-API-14');

it('FAQ-API-15: question max length enforced', function (): void {
    $response = $this->actingAs($this->owner)->postJson(faq_url($this->tenant), [
        'question' => str_repeat('a', 501),
        'answer' => 'answer',
    ]);

    $response->assertStatus(422);
})->group('FAQ-API-15');

it('FAQ-API-16: answer max length enforced', function (): void {
    $response = $this->actingAs($this->owner)->postJson(faq_url($this->tenant), [
        'question' => 'test',
        'answer' => str_repeat('a', 4097),
    ]);

    $response->assertStatus(422);
})->group('FAQ-API-16');

it('FAQ-API-17: empty normalized question rejected', function (): void {
    $response = $this->actingAs($this->owner)->postJson(faq_url($this->tenant), [
        'question' => '???',
        'answer' => 'answer',
    ]);

    $response->assertStatus(422)->assertJson([
        'code' => 'FAQ_INVALID_QUESTION',
    ]);
})->group('FAQ-API-17');

it('FAQ-API-18: invalid status rejected', function (): void {
    $response = $this->actingAs($this->owner)->postJson(faq_url($this->tenant), [
        'question' => 'test',
        'answer' => 'answer',
        'status' => 'published',
    ]);

    $response->assertStatus(422);
})->group('FAQ-API-18');

it('FAQ-API-19: invalid priority rejected', function (): void {
    $response = $this->actingAs($this->owner)->postJson(faq_url($this->tenant), [
        'question' => 'test',
        'answer' => 'answer',
        'priority' => 101,
    ]);

    $response->assertStatus(422);
})->group('FAQ-API-19');

it('FAQ-API-20: resource hides internals', function (): void {
    $faq = Faq::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $response = $this->actingAs($this->owner)->getJson(faq_url($this->tenant, $faq->id));

    $response->assertOk();

    $data = $response->json('faq');
    expect($data)->not->toHaveKey('tenant_id');
    expect($data)->not->toHaveKey('normalized_question');
    expect($data)->not->toHaveKey('deleted_at');
    expect($data)->toHaveKey('id');
    expect($data)->toHaveKey('question');
    expect($data)->toHaveKey('answer');
    expect($data)->toHaveKey('status');
    expect($data)->toHaveKey('priority');
    expect($data)->toHaveKey('created_at');
    expect($data)->toHaveKey('updated_at');
})->group('FAQ-API-20');
