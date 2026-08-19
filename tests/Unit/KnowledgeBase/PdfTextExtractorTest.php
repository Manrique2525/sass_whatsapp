<?php

declare(strict_types=1);

use App\Application\KnowledgeBase\Extractors\DocumentTextExtractorFactory;
use App\Application\KnowledgeBase\Extractors\DocxTextExtractor;
use App\Application\KnowledgeBase\Extractors\PdfTextExtractor;
use App\Application\KnowledgeBase\Extractors\PlainTextExtractor;
use App\Application\KnowledgeBase\Services\DocumentChunker;
use App\Application\KnowledgeBase\Services\TextNormalizer;
use App\Domain\KnowledgeBase\Exceptions\DocumentExtractionFailedException;
use App\Domain\KnowledgeBase\Exceptions\DocumentTextTooLargeException;

/*
|--------------------------------------------------------------------------
| FASE 17 U2.3 — PDF Extractor Unit Tests
|--------------------------------------------------------------------------
*/

function pdfExt(): PdfTextExtractor
{
    return new PdfTextExtractor;
}

/**
 * Build a minimal but valid PDF with the given text content.
 * Uses proper byte offsets in xref table for smalot compatibility.
 */
function buildMinimalPdf(string ...$textLines): string
{
    $header = "%PDF-1.4\n";

    $objects = [];

    $objects[] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
    $objects[] = "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";

    $stream = "BT\n/F1 16 Tf\n";
    $y = 700;

    foreach ($textLines as $line) {
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
        $stream .= "100 {$y} Td\n({$escaped}) Tj\n0 -30 Td\n";
        $y -= 30;
    }

    $stream .= 'ET';

    $objects[] = "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>\nendobj\n";
    $objects[] = "4 0 obj\n<< /Length ".strlen($stream)." >>\nstream\n{$stream}\nendstream\nendobj\n";
    $objects[] = "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";

    $body = '';
    $offsets = [];
    $offset = strlen($header);

    $objCount = count($objects);

    for ($i = 0; $i < $objCount; $i++) {
        $offsets[$i + 1] = $offset;
        $body .= $objects[$i];
        $offset += strlen($objects[$i]);
    }

    $xrefOffset = $offset;

    $xref = "xref\n0 ".($objCount + 1)."\n";
    $xref .= "0000000000 65535 f \n";

    for ($i = 1; $i <= $objCount; $i++) {
        $xref .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }

    $trailer = "trailer\n<< /Size ".($objCount + 1)." /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";

    return $header.$body.$xref.$trailer;
}

/*
|--------------------------------------------------------------------------
| Tests
|--------------------------------------------------------------------------
*/

test('EXT-PDF-01: valid simple PDF extracts text', function (): void {
    $pdf = buildMinimalPdf('Hello PDF World');
    $result = pdfExt()->extract($pdf);

    expect($result->text)->toContain('Hello PDF World');
    expect($result->characterCount)->toBeGreaterThan(0);
    expect($result->metadata['format'])->toBe('pdf');
    expect($result->metadata['pages'])->toBe(1);
});

test('EXT-PDF-02: multiline PDF preserves paragraph ordering', function (): void {
    $pdf = buildMinimalPdf('First paragraph', 'Second paragraph', 'Third paragraph');
    $result = pdfExt()->extract($pdf);

    expect($result->text)->toContain('First paragraph');
    expect($result->text)->toContain('Second paragraph');
    expect($result->text)->toContain('Third paragraph');

    $firstPos = strpos($result->text, 'First paragraph');
    $secondPos = strpos($result->text, 'Second paragraph');
    $thirdPos = strpos($result->text, 'Third paragraph');

    expect($firstPos)->toBeLessThan($secondPos);
    expect($secondPos)->toBeLessThan($thirdPos);
});

test('EXT-PDF-03: Unicode content preserved if parser returns it', function (): void {
    $pdf = buildMinimalPdf('Español content test');
    $result = pdfExt()->extract($pdf);

    expect($result->characterCount)->toBeGreaterThan(0);
    expect($result->metadata['format'])->toBe('pdf');
});

test('EXT-PDF-04: corrupt PDF throws DocumentExtractionFailedException', function (): void {
    pdfExt()->extract('This is not a PDF at all');
})->throws(DocumentExtractionFailedException::class);

test('EXT-PDF-05: empty PDF content throws DocumentExtractionFailedException', function (): void {
    pdfExt()->extract('');
})->throws(DocumentExtractionFailedException::class);

test('EXT-PDF-06: invalid PDF header throws DocumentExtractionFailedException', function (): void {
    $corrupt = '%PDF-1.4 fake encrypted content with no valid structure whatsoever';
    pdfExt()->extract($corrupt);
})->throws(DocumentExtractionFailedException::class);

test('EXT-PDF-07: extracted text exceeding max limit throws DocumentTextTooLargeException', function (): void {
    config(['knowledge.extraction.max_extracted_text_size' => 100]);

    $pdf = buildMinimalPdf(str_repeat('A', 200));
    pdfExt()->extract($pdf);
})->throws(DocumentTextTooLargeException::class);

test('EXT-PDF-08: factory resolves application/pdf to PdfTextExtractor', function (): void {
    $factory = new DocumentTextExtractorFactory(
        new PlainTextExtractor,
        new DocxTextExtractor,
        new PdfTextExtractor,
    );

    expect($factory->resolve('application/pdf'))->toBeInstanceOf(PdfTextExtractor::class);
});

test('EXT-PDF-09: normalization pipeline integration', function (): void {
    $pdf = buildMinimalPdf('Hello world from PDF document with enough text');
    $extractor = pdfExt();
    $normalizer = new TextNormalizer;

    $extracted = $extractor->extract($pdf);
    $normalized = $normalizer->normalizeAndValidate(
        $extracted->text,
        config('knowledge.extraction.max_extracted_text_size')
    );

    expect($normalized)->not->toBe('');
    expect($normalized)->toContain('Hello world from PDF document');
});

test('EXT-PDF-10: chunking pipeline integration', function (): void {
    $paragraphs = array_map(
        fn ($i) => "Paragraph {$i} with enough content to test chunking from PDF extraction.",
        range(0, 9)
    );

    $pdf = buildMinimalPdf(...$paragraphs);
    $extractor = pdfExt();
    $normalizer = new TextNormalizer;
    $chunker = new DocumentChunker;

    $extracted = $extractor->extract($pdf);
    $normalized = $normalizer->normalizeAndValidate(
        $extracted->text,
        config('knowledge.extraction.max_extracted_text_size')
    );
    $chunks = $chunker->chunk($normalized);

    expect($chunks)->not->toHaveCount(0);

    foreach ($chunks as $chunk) {
        expect($chunk->tokenCount)->toBeGreaterThan(0);
    }
});

test('EXT-PDF-11: parser internal exception not leaked to caller', function (): void {
    try {
        pdfExt()->extract('not a pdf');
        $this->fail('Expected exception not thrown');
    } catch (DocumentExtractionFailedException $e) {
        expect($e->getMessage())->not->toContain('smalot');
        expect($e->getMessage())->not->toContain('Smalot');
        expect($e->getMessage())->not->toContain('stack');
        expect($e->getMessage())->not->toContain('trace');
    }
});

test('EXT-PDF-12: PDF extraction does not create embeddings', function (): void {
    $pdf = buildMinimalPdf('Simple content');
    $result = pdfExt()->extract($pdf);

    expect($result->text)->not->toBe('');
    expect($result->metadata)->not->toHaveKey('embedding');
    expect($result->metadata)->not->toHaveKey('vector');
});
