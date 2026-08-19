<?php

declare(strict_types=1);

use App\Domain\Faq\ValueObjects\FaqQuestionNormalizer;

/*
|--------------------------------------------------------------------------
| FAQ Question Normalizer Tests (FASE 18 U1)
|--------------------------------------------------------------------------
|
| FAQ-NORM-01..12 — Normalización canónica de preguntas FAQ.
| Corren en SQLite :memory: (phpunit.xml default).
|
*/

beforeEach(function (): void {
    $this->normalizer = new FaqQuestionNormalizer;
});

it('FAQ-NORM-01: trims whitespace', function (): void {
    expect($this->normalizer->normalize('  hello  '))->toBe('hello');
})->group('FAQ-NORM-01');

it('FAQ-NORM-02: unicode lowercase', function (): void {
    expect($this->normalizer->normalize('HOLA MUNDO'))->toBe('hola mundo');
    expect($this->normalizer->normalize('ÑOÑO'))->toBe('ñoño');
})->group('FAQ-NORM-02');

it('FAQ-NORM-03: collapses multiple whitespace', function (): void {
    expect($this->normalizer->normalize('hello   world'))->toBe('hello world');
    expect($this->normalizer->normalize("hello\t\n  world"))->toBe('hello world');
})->group('FAQ-NORM-03');

it('FAQ-NORM-04: removes ¿ ? punctuation', function (): void {
    expect($this->normalizer->normalize('¿Cuál es tu horario?'))->toBe('cuál es tu horario');
    expect($this->normalizer->normalize('?hello?'))->toBe('hello');
})->group('FAQ-NORM-04');

it('FAQ-NORM-05: removes ¡ ! punctuation', function (): void {
    expect($this->normalizer->normalize('¡Hola!'))->toBe('hola');
    expect($this->normalizer->normalize('¡¡Hola mundo!!'))->toBe('hola mundo');
})->group('FAQ-NORM-05');

it('FAQ-NORM-06: preserves accented vowels', function (): void {
    expect($this->normalizer->normalize('¿Cuál es tu horario?'))->toBe('cuál es tu horario');
    expect($this->normalizer->normalize('teléfono'))->toBe('teléfono');
    expect($this->normalizer->normalize('corazón'))->toBe('corazón');
})->group('FAQ-NORM-06');

it('FAQ-NORM-07: preserves ñ', function (): void {
    expect($this->normalizer->normalize('año'))->toBe('año');
    expect($this->normalizer->normalize('ESPAÑA'))->toBe('españa');
    expect($this->normalizer->normalize('ñandú'))->toBe('ñandú');
})->group('FAQ-NORM-07');

it('FAQ-NORM-08: preserves emoji', function (): void {
    expect($this->normalizer->normalize('¿Tienes stock? 📦'))->toBe('tienes stock? 📦');
    expect($this->normalizer->normalize('👍🏼'))->toBe('👍🏼');
})->group('FAQ-NORM-08');

it('FAQ-NORM-09: NFC normalization', function (): void {
    // Composed form (NFC): é as single codepoint U+00E9
    $nfc = "\xC3\xA9"; // é (NFC)

    // Decomposed form (NFD): e + combining acute accent
    $nfd = "e\xCC\x81"; // e + U+0301

    $resultNfc = $this->normalizer->normalize($nfc);
    $resultNfd = $this->normalizer->normalize($nfd);

    expect($resultNfc)->toBe($resultNfd);
    expect($resultNfc)->toBe("\xC3\xA9"); // should be NFC
})->group('FAQ-NORM-09');

it('FAQ-NORM-10: idempotency — normalize twice equals once', function (): void {
    $inputs = [
        '¿Cuál es tu horario?',
        '  HOLA   MUNDO  ',
        '¡Bienvenido! 🎉',
        'teléfono',
        'año',
    ];

    foreach ($inputs as $input) {
        $once = $this->normalizer->normalize($input);
        $twice = $this->normalizer->normalize($once);
        expect($twice)->toBe($once);
    }
})->group('FAQ-NORM-10');

it('FAQ-NORM-11: empty and whitespace-only return empty', function (): void {
    expect($this->normalizer->normalize(''))->toBe('');
    expect($this->normalizer->normalize('   '))->toBe('');
    expect($this->normalizer->normalize("\t\n"))->toBe('');
})->group('FAQ-NORM-11');

it('FAQ-NORM-12: accent distinction preserved — Cuál ≠ cual', function (): void {
    $conAccent = $this->normalizer->normalize('¿Cuál es tu horario?');
    $sinAccent = $this->normalizer->normalize('cual es tu horario');

    expect($conAccent)->not->toBe($sinAccent);
    expect($conAccent)->toBe('cuál es tu horario');
    expect($sinAccent)->toBe('cual es tu horario');
})->group('FAQ-NORM-12');
