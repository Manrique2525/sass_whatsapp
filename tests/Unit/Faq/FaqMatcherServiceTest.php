<?php

declare(strict_types=1);

use App\Application\Faq\Services\FaqMatcherService;
use App\Domain\Faq\Enums\FaqStatus;
use App\Domain\Faq\Models\Faq;
use App\Domain\Faq\ValueObjects\FaqMatch;
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
| FAQ Matcher Tests (FASE 18 U2)
|--------------------------------------------------------------------------
|
| FAQ-MATCH-01..20 — Matching determinista, tenant isolation, edge cases.
| Corren en SQLite :memory: (phpunit.xml default).
|
*/

beforeEach(function (): void {
    $this->normalizer = new FaqQuestionNormalizer;
    $this->matcher = new FaqMatcherService($this->normalizer);
});

it('FAQ-MATCH-01: exact normalized match', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    Faq::factory()->create([
        'question' => '¿Cuál es tu horario?',
        'normalized_question' => 'cuál es tu horario',
        'answer' => 'Lunes a viernes de 9 a 18.',
        'status' => FaqStatus::Active,
    ]);

    $result = $this->matcher->match($tenant, '¿Cuál es tu horario?');

    expect($result)->toBeInstanceOf(FaqMatch::class);
    expect($result->answer)->toBe('Lunes a viernes de 9 a 18.');
    expect($result->matchType)->toBe('exact_normalized');
})->group('FAQ-MATCH-01');

it('FAQ-MATCH-02: trimmed match', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    Faq::factory()->create([
        'normalized_question' => 'cuál es tu horario',
        'status' => FaqStatus::Active,
    ]);

    $result = $this->matcher->match($tenant, '  ¿Cuál es tu horario?  ');

    expect($result)->not->toBeNull();
})->group('FAQ-MATCH-02');

it('FAQ-MATCH-03: case-insensitive Unicode match', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    Faq::factory()->create([
        'normalized_question' => 'cuál es tu horario',
        'status' => FaqStatus::Active,
    ]);

    $result = $this->matcher->match($tenant, '¿CUÁL ES TU HORARIO?');

    expect($result)->not->toBeNull();
})->group('FAQ-MATCH-03');

it('FAQ-MATCH-04: punctuation-normalized match', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    Faq::factory()->create([
        'normalized_question' => 'cuál es tu horario',
        'status' => FaqStatus::Active,
    ]);

    $result = $this->matcher->match($tenant, '¿Cuál es tu horario?');

    expect($result)->not->toBeNull();
})->group('FAQ-MATCH-04');

it('FAQ-MATCH-05: whitespace-normalized match', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    Faq::factory()->create([
        'normalized_question' => 'cuál es tu horario',
        'status' => FaqStatus::Active,
    ]);

    $result = $this->matcher->match($tenant, '¿Cuál   es   tu   horario?');

    expect($result)->not->toBeNull();
})->group('FAQ-MATCH-05');

it('FAQ-MATCH-06: accent difference does NOT match', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    Faq::factory()->create([
        'normalized_question' => 'cuál es tu horario',
        'status' => FaqStatus::Active,
    ]);

    $result = $this->matcher->match($tenant, 'cual es tu horario');

    expect($result)->toBeNull();
})->group('FAQ-MATCH-06');

it('FAQ-MATCH-07: ñ difference does NOT match', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    Faq::factory()->create([
        'normalized_question' => 'año',
        'status' => FaqStatus::Active,
    ]);

    $result = $this->matcher->match($tenant, 'ano');

    expect($result)->toBeNull();
})->group('FAQ-MATCH-07');

it('FAQ-MATCH-08: emoji preserved in matching', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    Faq::factory()->create([
        'question' => '¿Tienes stock? 📦',
        'normalized_question' => 'tienes stock? 📦',
        'status' => FaqStatus::Active,
    ]);

    $result = $this->matcher->match($tenant, '¿Tienes stock? 📦');

    expect($result)->not->toBeNull();
})->group('FAQ-MATCH-08');

it('FAQ-MATCH-09: inactive excluded', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    Faq::factory()->create([
        'normalized_question' => 'cuál es tu horario',
        'status' => FaqStatus::Inactive,
    ]);

    $result = $this->matcher->match($tenant, '¿Cuál es tu horario?');

    expect($result)->toBeNull();
})->group('FAQ-MATCH-09');

it('FAQ-MATCH-10: soft-deleted excluded', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    $faq = Faq::factory()->create([
        'normalized_question' => 'cuál es tu horario',
        'status' => FaqStatus::Active,
    ]);
    $faq->delete();

    $result = $this->matcher->match($tenant, '¿Cuál es tu horario?');

    expect($result)->toBeNull();
})->group('FAQ-MATCH-10');

it('FAQ-MATCH-11: tenant A cannot match B', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    TenantContext::setId($tenantB->id);
    Faq::factory()->create([
        'normalized_question' => 'cuál es tu horario',
        'status' => FaqStatus::Active,
    ]);

    TenantContext::setId($tenantA->id);
    $result = $this->matcher->match($tenantA, '¿Cuál es tu horario?');

    expect($result)->toBeNull();
})->group('FAQ-MATCH-11');

it('FAQ-MATCH-12: same normalized question different tenant resolves correct tenant', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    TenantContext::setId($tenantA->id);
    Faq::factory()->create([
        'normalized_question' => 'cuál es tu horario',
        'answer' => 'Respuesta A',
        'status' => FaqStatus::Active,
    ]);

    TenantContext::setId($tenantB->id);
    Faq::factory()->create([
        'normalized_question' => 'cuál es tu horario',
        'answer' => 'Respuesta B',
        'status' => FaqStatus::Active,
    ]);

    TenantContext::setId($tenantA->id);
    $resultA = $this->matcher->match($tenantA, '¿Cuál es tu horario?');

    TenantContext::setId($tenantB->id);
    $resultB = $this->matcher->match($tenantB, '¿Cuál es tu horario?');

    expect($resultA->answer)->toBe('Respuesta A');
    expect($resultB->answer)->toBe('Respuesta B');
    expect($resultA->faqId)->not->toBe($resultB->faqId);
})->group('FAQ-MATCH-12');

it('FAQ-MATCH-13: empty input returns null', function (): void {
    $tenant = Tenant::factory()->create();

    expect($this->matcher->match($tenant, ''))->toBeNull();
    expect($this->matcher->match($tenant, '   '))->toBeNull();
    expect($this->matcher->match($tenant, "\t\n"))->toBeNull();
})->group('FAQ-MATCH-13');

it('FAQ-MATCH-14: no match returns null', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    $result = $this->matcher->match($tenant, '¿Cuál es tu horario?');

    expect($result)->toBeNull();
})->group('FAQ-MATCH-14');

it('FAQ-MATCH-15: answer returned exactly as stored', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    $answer = "Lunes a viernes de 9:00 a 18:00.\nSábados de 10:00 a 14:00.";

    Faq::factory()->create([
        'normalized_question' => 'cuál es tu horario',
        'answer' => $answer,
        'status' => FaqStatus::Active,
    ]);

    $result = $this->matcher->match($tenant, '¿Cuál es tu horario?');

    expect($result->answer)->toBe($answer);
})->group('FAQ-MATCH-15');

it('FAQ-MATCH-16: matchType is exact_normalized', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    Faq::factory()->create([
        'normalized_question' => 'test',
        'status' => FaqStatus::Active,
    ]);

    $result = $this->matcher->match($tenant, 'test');

    expect($result->matchType)->toBe('exact_normalized');
})->group('FAQ-MATCH-16');

it('FAQ-MATCH-17: priority preserved in VO', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    Faq::factory()->create([
        'normalized_question' => 'test',
        'priority' => 42,
        'status' => FaqStatus::Active,
    ]);

    $result = $this->matcher->match($tenant, 'test');

    expect($result->priority)->toBe(42);
})->group('FAQ-MATCH-17');

it('FAQ-MATCH-18: SQL-looking input safe', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    Faq::factory()->create([
        'normalized_question' => "1' or '1'='1",
        'status' => FaqStatus::Active,
    ]);

    $result = $this->matcher->match($tenant, "1' OR '1'='1");

    expect($result)->not->toBeNull();
})->group('FAQ-MATCH-18');

it('FAQ-MATCH-19: HTML-looking input safe', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    Faq::factory()->create([
        'normalized_question' => '<script>alert("xss")</script>',
        'status' => FaqStatus::Active,
    ]);

    $result = $this->matcher->match($tenant, '<script>alert("xss")</script>');

    expect($result)->not->toBeNull();
    expect($result->answer)->not->toContain('<script>');
})->group('FAQ-MATCH-19');

it('FAQ-MATCH-20: matcher performs no writes', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    Faq::factory()->create([
        'normalized_question' => 'test',
        'status' => FaqStatus::Active,
    ]);

    $before = Faq::count();
    $this->matcher->match($tenant, 'test');
    $after = Faq::count();

    expect($after)->toBe($before);
})->group('FAQ-MATCH-20');
