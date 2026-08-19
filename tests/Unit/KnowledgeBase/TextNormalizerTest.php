<?php

declare(strict_types=1);

use App\Application\KnowledgeBase\Services\TextNormalizer;
use App\Domain\KnowledgeBase\Exceptions\DocumentExtractionFailedException;
use App\Domain\KnowledgeBase\Exceptions\DocumentTextTooLargeException;

/*
|--------------------------------------------------------------------------
| FASE 17 U2.3 — Text Normalizer Unit Tests
|--------------------------------------------------------------------------
*/

function normalizer(): TextNormalizer
{
    return new TextNormalizer;
}

test('NORM-01: CRLF normalized to LF', function (): void {
    $input = "Line1\r\nLine2\rLine3\nLine4";
    $result = normalizer()->normalize($input);

    expect($result)->not->toContain("\r");
    expect($result)->toContain("\n");
    expect($result)->toBe("Line1\nLine2\nLine3\nLine4");
});

test('NORM-02: null bytes removed', function (): void {
    $input = "Hello\0World";
    $result = normalizer()->normalize($input);

    expect($result)->toBe('HelloWorld');
});

test('NORM-03: control chars stripped but newlines preserved', function (): void {
    $input = "Hello\x08\x0E World\nSecond\x1F paragraph";
    $result = normalizer()->normalize($input);

    expect($result)->toBe("Hello World\nSecond paragraph");
    expect($result)->toContain("\n");
});

test('NORM-04: Unicode NFC normalization', function (): void {
    $decomposed = "caf\u{0065}\u{0301}";
    $result = normalizer()->normalize($decomposed);

    if (class_exists(Normalizer::class)) {
        expect(Normalizer::isNormalized($result, Normalizer::FORM_C))->toBeTrue();
    }

    expect($result)->toContain('é');
});

test('NORM-05: excess blank lines collapsed to double newline', function (): void {
    $input = "Paragraph1\n\n\n\n\nParagraph2";
    $result = normalizer()->normalize($input);

    expect($result)->toContain("Paragraph1\n\n");
    expect($result)->toContain("\n\nParagraph2");
    expect($result)->not->toContain("\n\n\n");
});

test('NORM-06: leading/trailing whitespace trimmed', function (): void {
    $input = "  \n  Hello World  \n  ";
    $result = normalizer()->normalize($input);

    expect($result)->toBe('Hello World');
});

test('NORM-07: inline whitespace normalized to single space', function (): void {
    $input = "Hello    world\t\twith   tabs";
    $result = normalizer()->normalize($input);

    expect($result)->toBe('Hello world with tabs');
});

test('NORM-08: empty string returns empty', function (): void {
    $result = normalizer()->normalize('');

    expect($result)->toBe('');
});

test('NORM-09: validate rejects oversized text', function (): void {
    $input = str_repeat('a', 1001);

    normalizer()->normalizeAndValidate($input, 1000);
})->throws(DocumentTextTooLargeException::class);

test('NORM-10: validate accepts text within limit', function (): void {
    $input = str_repeat('a', 1000);
    $result = normalizer()->normalizeAndValidate($input, 1000);

    expect($result)->toBe(str_repeat('a', 1000));
});

test('NORM-11: validate rejects empty text after normalization', function (): void {
    normalizer()->normalizeAndValidate("   \n\n   ", 1000);
})->throws(DocumentExtractionFailedException::class);
