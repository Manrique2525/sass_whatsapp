<?php

declare(strict_types=1);

use App\Domain\Leads\ValueObjects\LeadPhoneNormalizer;

/*
|--------------------------------------------------------------------------
| Lead Phone Normalizer Tests (FASE 19 U1)
|--------------------------------------------------------------------------
|
| LEAD-PHONE-01..10 — Normalización canónica de teléfonos.
| Corren en SQLite :memory: (phpunit.xml default).
|
*/

beforeEach(function (): void {
    $this->normalizer = new LeadPhoneNormalizer;
});

it('LEAD-PHONE-01: strips spaces and adds plus prefix', function (): void {
    expect($this->normalizer->normalize('+52 993 123 4567'))->toBe('+529931234567');
})->group('LEAD-PHONE-01');

it('LEAD-PHONE-02: strips dashes', function (): void {
    expect($this->normalizer->normalize('54-11-5555-4444'))->toBe('+541155554444');
})->group('LEAD-PHONE-02');

it('LEAD-PHONE-03: strips parentheses', function (): void {
    expect($this->normalizer->normalize('(11) 5555-4444'))->toBe('+1155554444');
})->group('LEAD-PHONE-03');

it('LEAD-PHONE-04: preserves leading plus from input', function (): void {
    expect($this->normalizer->normalize('+5491155554444'))->toBe('+5491155554444');
})->group('LEAD-PHONE-04');

it('LEAD-PHONE-05: handles digits-only input', function (): void {
    expect($this->normalizer->normalize('5491155554444'))->toBe('+5491155554444');
})->group('LEAD-PHONE-05');

it('LEAD-PHONE-06: trims whitespace before processing', function (): void {
    expect($this->normalizer->normalize('  +54 11 5555 4444  '))->toBe('+541155554444');
})->group('LEAD-PHONE-06');

it('LEAD-PHONE-07: empty string returns empty', function (): void {
    expect($this->normalizer->normalize(''))->toBe('');
})->group('LEAD-PHONE-07');

it('LEAD-PHONE-08: whitespace-only returns empty', function (): void {
    expect($this->normalizer->normalize('   '))->toBe('');
    expect($this->normalizer->normalize("\t\n"))->toBe('');
})->group('LEAD-PHONE-08');

it('LEAD-PHONE-09: strips common presentation characters', function (): void {
    expect($this->normalizer->normalize('+1 (800) 555-0199'))->toBe('+18005550199');
    expect($this->normalizer->normalize('+44 20 7946 0958'))->toBe('+442079460958');
})->group('LEAD-PHONE-09');

it('LEAD-PHONE-10: idempotency — normalize twice equals once', function (): void {
    $inputs = [
        '+52 993 123 4567',
        '5491155554444',
        '(11) 5555-4444',
        '+1 (800) 555-0199',
    ];

    foreach ($inputs as $input) {
        $once = $this->normalizer->normalize($input);
        $twice = $this->normalizer->normalize($once);
        expect($twice)->toBe($once);
    }
})->group('LEAD-PHONE-10');
