<?php

declare(strict_types=1);

use App\Application\KnowledgeBase\Extractors\DocumentTextExtractorFactory;
use App\Application\KnowledgeBase\Extractors\DocxTextExtractor;
use App\Application\KnowledgeBase\Extractors\PdfTextExtractor;
use App\Application\KnowledgeBase\Extractors\PlainTextExtractor;
use App\Application\KnowledgeBase\Services\DocumentChunker;
use App\Application\KnowledgeBase\Services\TextNormalizer;
use App\Domain\KnowledgeBase\Exceptions\DocumentExtractionFailedException;
use App\Domain\KnowledgeBase\ValueObjects\ExtractedText;

/*
|--------------------------------------------------------------------------
| FASE 17 U2.3 - Extraction Pipeline Feature Tests (pure services, no DB)
|--------------------------------------------------------------------------
*/

function pipelineFactory(): DocumentTextExtractorFactory
{
    return new DocumentTextExtractorFactory(
        new PlainTextExtractor,
        new DocxTextExtractor,
        new PdfTextExtractor,
    );
}

function fullPipeline(string $mimeType, string $rawContent): array
{
    $factory = pipelineFactory();
    $normalizer = new TextNormalizer;
    $chunker = new DocumentChunker;

    $extractor = $factory->resolve($mimeType);
    $extracted = $extractor->extract($rawContent);
    $normalized = $normalizer->normalizeAndValidate($extracted->text, config('knowledge.extraction.max_extracted_text_size'));
    $chunks = $chunker->chunk($normalized);

    return [
        'extracted' => $extracted,
        'normalized' => $normalized,
        'chunks' => $chunks,
    ];
}

test('pipeline: plain text produces valid chunks', function (): void {
    $text = "First paragraph with enough content to pass minimum chunk length. It has multiple sentences for splitting purposes.\n\nSecond paragraph also has sufficient content. This ensures it becomes its own chunk.\n\nThird paragraph to ensure we have at least a few chunks for testing overlap.";
    $result = fullPipeline('text/plain', $text);

    expect($result['extracted'])->toBeInstanceOf(ExtractedText::class);
    expect($result['extracted']->characterCount)->toBeGreaterThan(0);
    expect($result['extracted']->text)->not->toBe('');
    expect($result['chunks'])->not->toHaveCount(0);

    foreach ($result['chunks'] as $chunk) {
        expect($chunk->tokenCount)->toBeGreaterThan(0);
    }
});

test('pipeline: factory resolves correct extractor', function (): void {
    $factory = pipelineFactory();

    expect($factory->resolve('text/plain'))->toBeInstanceOf(PlainTextExtractor::class);
    expect($factory->resolve('application/vnd.openxmlformats-officedocument.wordprocessingml.document'))->toBeInstanceOf(DocxTextExtractor::class);
    expect($factory->resolve('application/pdf'))->toBeInstanceOf(PdfTextExtractor::class);
});

test('pipeline: factory rejects unknown MIME types', function (): void {
    $factory = pipelineFactory();
    $factory->resolve('application/json');
})->throws(DocumentExtractionFailedException::class);

test('pipeline: list supported mime types', function (): void {
    $factory = pipelineFactory();
    $supported = $factory->supportedMimeTypes();

    expect($supported)->toContain('text/plain');
    expect($supported)->toContain('application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    expect($supported)->toContain('application/pdf');
});
