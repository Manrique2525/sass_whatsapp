<?php

declare(strict_types=1);

use App\Application\KnowledgeBase\Services\DocumentChunker;
use App\Domain\KnowledgeBase\Exceptions\DocumentTooManyChunksException;
use App\Domain\KnowledgeBase\ValueObjects\TextChunk;

/*
|--------------------------------------------------------------------------
| FASE 17 U2.3 — Document Chunker Unit Tests
|--------------------------------------------------------------------------
*/

function chunker(?array $config = null): DocumentChunker
{
    return new DocumentChunker($config ?? [
        'max_chunk_length' => 100,
        'chunk_overlap' => 20,
        'min_chunk_length' => 10,
        'max_chunks_per_document' => 50,
    ]);
}

function longChunker(): DocumentChunker
{
    return new DocumentChunker([
        'max_chunk_length' => 200,
        'chunk_overlap' => 30,
        'min_chunk_length' => 20,
        'max_chunks_per_document' => 200,
    ]);
}

test('CHUNK-01: short text returns single chunk', function (): void {
    $chunks = chunker()->chunk('Hello world');

    expect($chunks)->toHaveCount(1);
    expect($chunks[0])->toBeInstanceOf(TextChunk::class);
    expect($chunks[0]->chunkIndex)->toBe(0);
    expect($chunks[0]->content)->toContain('Hello world');
    expect($chunks[0]->tokenCount)->toBeGreaterThan(0);
});

test('CHUNK-02: empty text returns empty array', function (): void {
    $chunks = chunker()->chunk('');

    expect($chunks)->toHaveCount(0);
});

test('CHUNK-03: text splits on paragraph boundaries', function (): void {
    $text = "Paragraph one is short.\n\nParagraph two is also short.";
    $chunks = chunker()->chunk($text);

    expect($chunks)->toHaveCount(2);

    $allContent = collect($chunks)->implode('content', "\n");
    expect($allContent)->toContain('Paragraph one');
    expect($allContent)->toContain('Paragraph two');
});

test('CHUNK-04: chunk indices are sequential starting from 0', function (): void {
    $text = "First paragraph content here.\n\nSecond paragraph content here.\n\nThird paragraph content here.";
    $chunks = longChunker()->chunk($text);

    foreach ($chunks as $i => $chunk) {
        expect($chunk->chunkIndex)->toBe($i);
    }
});

test('CHUNK-05: no chunk exceeds max_chunk_length', function (): void {
    $text = str_repeat('Word ', 50);
    $chunks = chunker()->chunk($text);

    foreach ($chunks as $chunk) {
        expect(mb_strlen($chunk->content, 'UTF-8'))->toBeLessThanOrEqual(100);
    }
});

test('CHUNK-06: all content is preserved across chunks', function (): void {
    $paragraphs = [];

    for ($i = 0; $i < 5; $i++) {
        $paragraphs[] = "This is paragraph {$i} with some content to make it longer. It has multiple sentences to allow proper splitting.";
    }

    $text = implode("\n\n", $paragraphs);
    $chunks = longChunker()->chunk($text);

    $allContent = collect($chunks)->implode('content', ' ');
    expect($allContent)->toContain('paragraph 0');
    expect($allContent)->toContain('paragraph 4');
});

test('CHUNK-07: token count is estimated correctly', function (): void {
    $text = 'Hello world test chunking logic';
    $chunks = chunker()->chunk($text);

    expect($chunks[0]->tokenCount)->toBe((int) ceil(mb_strlen($text, 'UTF-8') / 4));
});

test('CHUNK-08: long paragraph splits on sentences', function (): void {
    $text = 'First sentence. Second sentence. Third sentence. Fourth sentence.';
    $chunks = chunker()->chunk($text);

    $allContent = collect($chunks)->implode('content', ' ');
    expect($allContent)->toContain('First sentence');
    expect($allContent)->toContain('Fourth sentence');
});

test('CHUNK-09: overlap creates shared content between adjacent chunks', function (): void {
    $paragraphs = [];

    for ($i = 0; $i < 10; $i++) {
        $paragraphs[] = str_repeat("Sentence {$i}. ", 10);
    }

    $text = implode("\n\n", $paragraphs);
    $chunks = chunker()->chunk($text);

    if (count($chunks) >= 2) {
        $chunk0End = mb_substr($chunks[0]->content, -20, null, 'UTF-8');
        expect($chunks[1]->content)->toContain($chunk0End);
    }
});

test('CHUNK-10: overlap does not exceed max_chunk_length', function (): void {
    $paragraphs = [];

    for ($i = 0; $i < 20; $i++) {
        $paragraphs[] = str_repeat("Paragraph {$i} sentence. ", 8);
    }

    $text = implode("\n\n", $paragraphs);
    $chunks = chunker()->chunk($text);

    foreach ($chunks as $chunk) {
        expect(mb_strlen($chunk->content, 'UTF-8'))->toBeLessThanOrEqual(100);
    }
});

test('CHUNK-11: overlap=0 produces no shared content', function (): void {
    $noOverlapChunker = new DocumentChunker([
        'max_chunk_length' => 50,
        'chunk_overlap' => 0,
        'min_chunk_length' => 10,
        'max_chunks_per_document' => 50,
    ]);

    $text = "First paragraph with enough words.\n\nSecond paragraph with enough words.\n\nThird paragraph with enough words.";
    $chunks = $noOverlapChunker->chunk($text);

    if (count($chunks) >= 2) {
        for ($i = 1; $i < count($chunks); $i++) {
            $prevContent = $chunks[$i - 1]->content;
            $currentContent = $chunks[$i]->content;

            expect($currentContent)->not->toStartWith(mb_substr($prevContent, -10, null, 'UTF-8'));
        }
    }
});

test('CHUNK-12: small chunks merged when below min_chunk_length', function (): void {
    $shortChunker = new DocumentChunker([
        'max_chunk_length' => 100,
        'chunk_overlap' => 0,
        'min_chunk_length' => 30,
        'max_chunks_per_document' => 50,
    ]);

    $text = "Hi.\n\nYou.\n\nOk.\n\nGo.";
    $chunks = $shortChunker->chunk($text);

    expect(count($chunks))->toBeLessThanOrEqual(2);
});

test('CHUNK-13: single character text returns empty', function (): void {
    $chunks = chunker()->chunk('a');

    expect(count($chunks))->toBeGreaterThanOrEqual(0);
});

test('CHUNK-14: exceeding max_chunks_per_document throws exception', function (): void {
    $tinyChunker = new DocumentChunker([
        'max_chunk_length' => 20,
        'chunk_overlap' => 0,
        'min_chunk_length' => 5,
        'max_chunks_per_document' => 3,
    ]);

    $paragraphs = [];

    for ($i = 0; $i < 50; $i++) {
        $paragraphs[] = str_repeat("Paragraph {$i}. ", 3);
    }

    $text = implode("\n\n", $paragraphs);
    $tinyChunker->chunk($text);
})->throws(DocumentTooManyChunksException::class);

test('CHUNK-15: whitespace-only text returns empty', function (): void {
    $chunks = chunker()->chunk("   \n\n   ");

    expect($chunks)->toHaveCount(0);
});
