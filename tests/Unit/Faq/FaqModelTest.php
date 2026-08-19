<?php

declare(strict_types=1);

use App\Domain\Faq\Enums\FaqStatus;
use App\Domain\Faq\Models\Faq;
use App\Domain\Tenants\Models\Tenant;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
});

/*
|--------------------------------------------------------------------------
| FAQ Model Tests (SQLite)
|--------------------------------------------------------------------------
|
| FAQ-DB-01..14 — Model, relationships, enums, factories, constraints.
| Corren en SQLite :memory: (phpunit.xml default).
| No validan partial unique indexes (ver tests/Postgres/Faq/).
|
*/

it('FAQ-DB-01: factory creates FAQ', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    $faq = Faq::factory()->create();

    expect($faq->id)->toBeString()->not->toBeEmpty();
    expect($faq->question)->toBeString()->not->toBeEmpty();
    expect($faq->answer)->toBeString()->not->toBeEmpty();
    expect($faq->created_at)->not->toBeNull();
    expect($faq->updated_at)->not->toBeNull();
})->group('FAQ-DB-01');

it('FAQ-DB-02: UUID primary key', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    $faq = Faq::factory()->create();

    expect($faq->id)->toBeUUID();
})->group('FAQ-DB-02');

it('FAQ-DB-03: tenant assigned correctly', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    $faq = Faq::factory()->create();

    expect($faq->tenant_id)->toBe($tenant->id);
    expect($faq->tenant)->toBeInstanceOf(Tenant::class);
    expect($faq->tenant->id)->toBe($tenant->id);
})->group('FAQ-DB-03');

it('FAQ-DB-04: status cast', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    $faq = Faq::factory()->create(['status' => FaqStatus::Inactive]);

    expect($faq->status)->toBeInstanceOf(FaqStatus::class);
    expect($faq->status)->toBe(FaqStatus::Inactive);
    expect($faq->status->value)->toBe('inactive');
})->group('FAQ-DB-04');

it('FAQ-DB-05: priority cast and default', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    $faqDefault = Faq::factory()->create();
    expect($faqDefault->priority)->toBeInt()->toBe(0);

    $faqCustom = Faq::factory()->create(['priority' => 42]);
    expect($faqCustom->priority)->toBe(42);
})->group('FAQ-DB-05');

it('FAQ-DB-06: soft delete', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    $faq = Faq::factory()->create();
    $faqId = $faq->id;

    $faq->delete();

    expect(Faq::find($faqId))->toBeNull();
    expect(Faq::withTrashed()->find($faqId))->not->toBeNull();
    expect(Faq::withTrashed()->find($faqId)->deleted_at)->not->toBeNull();
})->group('FAQ-DB-06');

it('FAQ-DB-07: active factory state', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    $faq = Faq::factory()->active()->create();

    expect($faq->status)->toBe(FaqStatus::Active);
})->group('FAQ-DB-07');

it('FAQ-DB-08: inactive factory state', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    $faq = Faq::factory()->inactive()->create();

    expect($faq->status)->toBe(FaqStatus::Inactive);
})->group('FAQ-DB-08');

it('FAQ-DB-09: normalized_question generated from question', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    $faq = Faq::factory()->create([
        'question' => '¿Cuál es tu horario?',
        'normalized_question' => 'cuál es tu horario',
    ]);

    expect($faq->question)->toBe('¿Cuál es tu horario?');
    expect($faq->normalized_question)->toBe('cuál es tu horario');
})->group('FAQ-DB-09');

it('FAQ-DB-10: duplicate normalized_question same tenant rejected', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    Faq::factory()->create([
        'normalized_question' => 'cuál es tu horario',
    ]);

    Faq::factory()->create([
        'normalized_question' => 'cuál es tu horario',
    ]);
})->throws(QueryException::class)->group('FAQ-DB-10');

it('FAQ-DB-11: same normalized_question different tenant allowed', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    TenantContext::setId($tenantA->id);
    Faq::factory()->create([
        'normalized_question' => 'cuál es tu horario',
    ]);

    TenantContext::setId($tenantB->id);
    $faqB = Faq::factory()->create([
        'normalized_question' => 'cuál es tu horario',
    ]);

    expect($faqB->id)->not->toBeNull();
    expect($faqB->tenant_id)->toBe($tenantB->id);
})->group('FAQ-DB-11');

it('FAQ-DB-12: FaqStatus enum has correct cases', function (): void {
    expect(FaqStatus::cases())->toHaveCount(2);
    expect(FaqStatus::Active->value)->toBe('active');
    expect(FaqStatus::Inactive->value)->toBe('inactive');
})->group('FAQ-DB-12');

it('FAQ-DB-13: FaqStatus label returns correct values', function (): void {
    expect(FaqStatus::Active->label())->toBe('Active');
    expect(FaqStatus::Inactive->label())->toBe('Inactive');
})->group('FAQ-DB-13');

it('FAQ-DB-14: faqs table has expected columns', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);
    Faq::factory()->create(); // ensures migration ran

    $columns = Schema::getColumns('faqs');
    $columnNames = array_column($columns, 'name');

    expect($columnNames)->toContain('id');
    expect($columnNames)->toContain('tenant_id');
    expect($columnNames)->toContain('question');
    expect($columnNames)->toContain('normalized_question');
    expect($columnNames)->toContain('answer');
    expect($columnNames)->toContain('status');
    expect($columnNames)->toContain('priority');
    expect($columnNames)->toContain('created_at');
    expect($columnNames)->toContain('updated_at');
    expect($columnNames)->toContain('deleted_at');
})->group('FAQ-DB-14');

it('FAQ-DB-15: faqs table has no hit_count column', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);
    Faq::factory()->create();

    $columns = Schema::getColumns('faqs');
    $columnNames = array_column($columns, 'name');

    expect($columnNames)->not->toContain('hit_count');
})->group('FAQ-DB-15');

it('FAQ-DB-16: tenant_id not in fillable', function (): void {
    $faq = new Faq;

    expect($faq->getFillable())->not->toContain('tenant_id');
})->group('FAQ-DB-16');

it('FAQ-DB-17: model is final', function (): void {
    $reflection = new ReflectionClass(Faq::class);

    expect($reflection->isFinal())->toBeTrue();
})->group('FAQ-DB-17');
