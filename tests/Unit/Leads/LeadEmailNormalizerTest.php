<?php

declare(strict_types=1);

use App\Domain\Leads\ValueObjects\LeadEmailNormalizer;

/*
|--------------------------------------------------------------------------
| Lead Email Normalizer Tests (FASE 19 U1)
|--------------------------------------------------------------------------
|
| LEAD-EMAIL-01..08 — Normalización canónica de emails.
| Corren en SQLite :memory: (phpunit.xml default).
|
*/

beforeEach(function (): void {
    $this->normalizer = new LeadEmailNormalizer;
});

it('LEAD-EMAIL-01: trims whitespace', function (): void {
    expect($this->normalizer->normalize('  user@example.com  '))->toBe('user@example.com');
})->group('LEAD-EMAIL-01');

it('LEAD-EMAIL-02: lowercases', function (): void {
    expect($this->normalizer->normalize('USER@EXAMPLE.COM'))->toBe('user@example.com');
})->group('LEAD-EMAIL-02');

it('LEAD-EMAIL-03: preserves plus addressing', function (): void {
    expect($this->normalizer->normalize('user+tag@domain.org'))->toBe('user+tag@domain.org');
})->group('LEAD-EMAIL-03');

it('LEAD-EMAIL-04: trims and lowercases together', function (): void {
    expect($this->normalizer->normalize('  Juan@Example.COM  '))->toBe('juan@example.com');
})->group('LEAD-EMAIL-04');

it('LEAD-EMAIL-05: preserves accented characters in local part', function (): void {
    expect($this->normalizer->normalize('Ünïcödé@EXAMPLE.COM'))->toBe('ünïcödé@example.com');
})->group('LEAD-EMAIL-05');

it('LEAD-EMAIL-06: idempotency — normalize twice equals once', function (): void {
    $inputs = [
        'user@example.com',
        '  USER+TAG@DOMAIN.ORG  ',
        'Ünïcödé@EXAMPLE.COM',
    ];

    foreach ($inputs as $input) {
        $once = $this->normalizer->normalize($input);
        $twice = $this->normalizer->normalize($once);
        expect($twice)->toBe($once);
    }
})->group('LEAD-EMAIL-06');

it('LEAD-EMAIL-07: does not strip plus addressing', function (): void {
    expect($this->normalizer->normalize('user+newsletter@example.com'))->toBe('user+newsletter@example.com');
    expect($this->normalizer->normalize('user+tag+more@example.com'))->toBe('user+tag+more@example.com');
})->group('LEAD-EMAIL-07');

it('LEAD-EMAIL-08: subdomain preserved', function (): void {
    expect($this->normalizer->normalize('USER@MAIL.EXAMPLE.COM'))->toBe('user@mail.example.com');
})->group('LEAD-EMAIL-08');
