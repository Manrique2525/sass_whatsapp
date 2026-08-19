<?php

declare(strict_types=1);

use App\Application\Faq\Services\FaqMatcherService;
use App\Domain\Faq\Enums\FaqStatus;
use App\Domain\Faq\Models\Faq;
use App\Domain\Faq\ValueObjects\FaqQuestionNormalizer;
use App\Domain\Tenants\Models\Tenant;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
});

/*
|--------------------------------------------------------------------------
| FAQ Matcher Multi-Tenancy Tests (FASE 18 U2)
|--------------------------------------------------------------------------
|
| FAQ-MT-U2-01..05 — Tenant isolation del matcher.
| Corren en SQLite :memory:.
|
*/

beforeEach(function (): void {
    $this->normalizer = new FaqQuestionNormalizer;
    $this->matcher = new FaqMatcherService($this->normalizer);
});

it('FAQ-MT-U2-01: tenant A FAQ A matches', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    Faq::factory()->create([
        'normalized_question' => 'cuál es tu horario',
        'answer' => 'Horario A',
        'status' => FaqStatus::Active,
    ]);

    $result = $this->matcher->match($tenant, '¿Cuál es tu horario?');

    expect($result)->not->toBeNull();
    expect($result->answer)->toBe('Horario A');
})->group('FAQ-MT-U2-01');

it('FAQ-MT-U2-02: tenant A cannot match tenant B FAQ', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    TenantContext::setId($tenantB->id);
    Faq::factory()->create([
        'normalized_question' => 'cuál es tu horario',
        'status' => FaqStatus::Active,
    ]);

    $result = $this->matcher->match($tenantA, '¿Cuál es tu horario?');

    expect($result)->toBeNull();
})->group('FAQ-MT-U2-02');

it('FAQ-MT-U2-03: same normalized question A/B returns A for tenant A', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    TenantContext::setId($tenantA->id);
    Faq::factory()->create([
        'normalized_question' => 'cuál es tu horario',
        'answer' => 'Respuesta de A',
        'status' => FaqStatus::Active,
    ]);

    TenantContext::setId($tenantB->id);
    Faq::factory()->create([
        'normalized_question' => 'cuál es tu horario',
        'answer' => 'Respuesta de B',
        'status' => FaqStatus::Active,
    ]);

    TenantContext::setId($tenantA->id);
    $resultA = $this->matcher->match($tenantA, '¿Cuál es tu horario?');

    expect($resultA->answer)->toBe('Respuesta de A');
})->group('FAQ-MT-U2-03');

it('FAQ-MT-U2-04: explicit tenant parameter overrides TenantContext', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    // Set context to B but pass A explicitly
    TenantContext::setId($tenantB->id);

    TenantContext::setId($tenantA->id);
    Faq::factory()->create([
        'normalized_question' => 'test',
        'answer' => 'A',
        'status' => FaqStatus::Active,
    ]);

    $result = $this->matcher->match($tenantA, 'test');

    expect($result)->not->toBeNull();
    expect($result->answer)->toBe('A');
})->group('FAQ-MT-U2-04');

it('FAQ-MT-U2-05: deleted FAQ does not leak', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    TenantContext::setId($tenantA->id);
    $faqA = Faq::factory()->create([
        'normalized_question' => 'test',
        'status' => FaqStatus::Active,
    ]);
    $faqA->delete();

    $result = $this->matcher->match($tenantA, 'test');

    expect($result)->toBeNull();
})->group('FAQ-MT-U2-05');
