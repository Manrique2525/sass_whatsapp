<?php

declare(strict_types=1);

use App\Application\KnowledgeBase\Extractors\PlainTextExtractor;
use App\Domain\KnowledgeBase\Exceptions\DocumentExtractionFailedException;

/*
|--------------------------------------------------------------------------
| FASE 17 U2.3 — TXT Extractor Unit Tests
|--------------------------------------------------------------------------
*/

function txtExt(): PlainTextExtractor
{
    return new PlainTextExtractor;
}

test('EXT-TXT-01: valid UTF-8 text extracted correctly', function (): void {
    $text = "Hello world.\nSecond line.";
    $result = txtExt()->extract($text);

    expect($result->text)->toBe("Hello world.\nSecond line.");
    expect($result->characterCount)->toBe(25);
    expect($result->metadata['format'])->toBe('txt');
});

test('EXT-TXT-02: BOM is stripped', function (): void {
    $text = "\xEF\xBB\xBFHello with BOM";
    $result = txtExt()->extract($text);

    expect($result->text)->toBe('Hello with BOM');
    expect($result->characterCount)->toBe(14);
});

test('EXT-TXT-03: CRLF normalization left to TextNormalizer (extractor passes through)', function (): void {
    $text = "Line1\r\nLine2\rLine3\nLine4";
    $result = txtExt()->extract($text);

    // PlainTextExtractor does not normalize line endings — TextNormalizer does.
    expect($result->text)->toBe("Line1\r\nLine2\rLine3\nLine4");
});

test('EXT-TXT-04: null bytes rejected', function (): void {
    $text = "Hello\0World";

    txtExt()->extract($text);
})->throws(DocumentExtractionFailedException::class);

test('EXT-TXT-05: binary content rejected', function (): void {
    $binary = "\x00\x01\x02\x03\x04\x05\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12";

    txtExt()->extract($binary);
})->throws(DocumentExtractionFailedException::class);

test('EXT-TXT-06: Unicode content preserved', function (): void {
    $text = "Español: ñ, á, é, í, ó, ú\n日本語テスト\nالعربية";
    $result = txtExt()->extract($text);

    expect($result->text)->toContain('ñ');
    expect($result->text)->toContain('日本語');
    expect($result->characterCount)->toBe(mb_strlen($text, 'UTF-8'));
});

test('EXT-TXT-07: empty content returns empty ExtractedText', function (): void {
    $result = txtExt()->extract('');

    expect($result->text)->toBe('');
    expect($result->characterCount)->toBe(0);
});

test('EXT-TXT-08: control chars stripped but newlines preserved', function (): void {
    $text = "Hello\x08\x0E World\nSecond\x1F paragraph";
    $result = txtExt()->extract($text);

    expect($result->text)->toBe("Hello World\nSecond paragraph");
    expect($result->text)->not->toContain("\x08");
    expect($result->text)->not->toContain("\x0E");
    expect($result->text)->toContain("\n");
});

test('EXT-TXT-09: tabs preserved as control chars in text', function (): void {
    $text = "Hello\tWorld";
    $result = txtExt()->extract($text);

    expect($result->text)->toContain("\t");
});

test('EXT-TXT-10: UTF-8 invalid bytes are sanitized to valid output', function (): void {
    $invalid = "\xC3\x28"; // invalid UTF-8 sequence

    $result = txtExt()->extract($invalid);

    expect($result->text)->not->toBe('')
        ->and(mb_check_encoding($result->text, 'UTF-8'))->toBeTrue();
});
