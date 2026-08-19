<?php

declare(strict_types=1);

namespace App\Application\KnowledgeBase\Services;

use App\Domain\KnowledgeBase\Exceptions\DocumentTooManyChunksException;
use App\Domain\KnowledgeBase\ValueObjects\TextChunk;

/**
 * Chunking determinista paragraph-aware (FASE 17 U2.3).
 *
 * Servicio puro y determinista. Sin DB, sin tenant, sin AI.
 *
 * Estrategia:
 * 1. Párrafos
 * 2. Sentences
 * 3. Words
 * 4. Hard split (último recurso)
 *
 * Garantías:
 * - No chunks vacíos
 * - Orden estable
 * - chunk_index estable
 * - Máximo de longitud
 * - Overlap controlado
 * - No crecimiento infinito
 */
final class DocumentChunker
{
    private int $maxChunkLength;

    private int $chunkOverlap;

    private int $minChunkLength;

    private int $maxChunks;

    /**
     * @param  array{max_chunk_length?: int, chunk_overlap?: int, min_chunk_length?: int, max_chunks_per_document?: int}|null  $config
     */
    public function __construct(?array $config = null)
    {
        $cfg = $config ?? config('knowledge.chunking');

        $this->maxChunkLength = $cfg['max_chunk_length'] ?? 1500;
        $this->chunkOverlap = $cfg['chunk_overlap'] ?? 200;
        $this->minChunkLength = $cfg['min_chunk_length'] ?? 50;
        $this->maxChunks = $cfg['max_chunks_per_document'] ?? 500;
    }

    /**
     * @return TextChunk[]
     *
     * @throws DocumentTooManyChunksException
     */
    public function chunk(string $text): array
    {
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        $paragraphs = $this->splitIntoParagraphs($text);

        $rawChunks = [];

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);

            if ($paragraph === '') {
                continue;
            }

            if (mb_strlen($paragraph, 'UTF-8') <= $this->maxChunkLength) {
                $rawChunks[] = $paragraph;
            } else {
                $sentences = $this->splitIntoSentences($paragraph);
                $this->splitSentencesIntoChunks($sentences, $rawChunks);
            }
        }

        $mergedChunks = $this->mergeSmallChunks($rawChunks);

        $overlappedChunks = $this->applyOverlap($mergedChunks);

        if (count($overlappedChunks) > $this->maxChunks) {
            throw new DocumentTooManyChunksException($this->maxChunks);
        }

        $result = [];

        foreach ($overlappedChunks as $index => $chunkContent) {
            $result[] = new TextChunk(
                content: $chunkContent,
                chunkIndex: $index,
                tokenCount: $this->estimateTokens($chunkContent),
                metadata: [],
            );
        }

        return $result;
    }

    /**
     * @return string[]
     */
    private function splitIntoParagraphs(string $text): array
    {
        $paragraphs = preg_split('/\n{2,}/', $text, -1, PREG_SPLIT_NO_EMPTY);

        return $paragraphs !== false ? $paragraphs : [$text];
    }

    /**
     * @return string[]
     */
    private function splitIntoSentences(string $text): array
    {
        $sentences = preg_split(
            '/(?<=[.!?;:])\s+/u',
            $text,
            -1,
            PREG_SPLIT_NO_EMPTY
        );

        return $sentences !== false ? $sentences : [$text];
    }

    /**
     * @param  string[]  $sentences
     * @param  string[]  $rawChunks  Modified in place via reference.
     */
    private function splitSentencesIntoChunks(array $sentences, array &$rawChunks): void
    {
        $current = '';

        foreach ($sentences as $sentence) {
            $sentenceLen = mb_strlen($sentence, 'UTF-8');
            $currentLen = mb_strlen($current, 'UTF-8');

            if ($currentLen > 0 && ($currentLen + 1 + $sentenceLen) > $this->maxChunkLength) {
                $rawChunks[] = $current;
                $current = $sentence;
            } else {
                $current = $current === '' ? $sentence : $current.' '.$sentence;
            }
        }

        if ($current !== '') {
            if (mb_strlen($current, 'UTF-8') > $this->maxChunkLength) {
                $this->hardSplit($current, $rawChunks);
            } else {
                $rawChunks[] = $current;
            }
        }
    }

    /**
     * @param  string[]  $rawChunks  Modified in place via reference.
     */
    private function hardSplit(string $text, array &$rawChunks): void
    {
        $len = mb_strlen($text, 'UTF-8');

        $offset = 0;

        while ($offset < $len) {
            $chunk = mb_substr($text, $offset, $this->maxChunkLength, 'UTF-8');

            if ($chunk === '') {
                break;
            }

            $rawChunks[] = $chunk;
            $offset += $this->maxChunkLength;
        }
    }

    /**
     * @param  string[]  $chunks
     * @return string[]
     */
    private function mergeSmallChunks(array $chunks): array
    {
        if ($chunks === []) {
            return [];
        }

        $merged = [];
        $pending = $chunks[0];

        for ($i = 1, $count = count($chunks); $i < $count; $i++) {
            $pendingLen = mb_strlen($pending, 'UTF-8');
            $nextLen = mb_strlen($chunks[$i], 'UTF-8');

            if ($pendingLen < $this->minChunkLength) {
                $combinedLen = $pendingLen + 1 + $nextLen;

                if ($combinedLen <= $this->maxChunkLength) {
                    $pending = $pending."\n".$chunks[$i];
                } else {
                    $merged[] = $pending;
                    $pending = $chunks[$i];
                }
            } else {
                $merged[] = $pending;
                $pending = $chunks[$i];
            }
        }

        $merged[] = $pending;

        return $merged;
    }

    /**
     * @param  string[]  $chunks
     * @return string[]
     */
    private function applyOverlap(array $chunks): array
    {
        if ($chunks === [] || $this->chunkOverlap <= 0) {
            return $chunks;
        }

        $result = [$chunks[0]];

        for ($i = 1, $count = count($chunks); $i < $count; $i++) {
            $prevChunk = $chunks[$i - 1];
            $prevLen = mb_strlen($prevChunk, 'UTF-8');

            $overlapStart = max(0, $prevLen - $this->chunkOverlap);
            $overlap = mb_substr($prevChunk, $overlapStart, $this->chunkOverlap, 'UTF-8');

            $overlapped = $overlap."\n".$chunks[$i];

            if (mb_strlen($overlapped, 'UTF-8') > $this->maxChunkLength) {
                $result[] = $chunks[$i];
            } else {
                $result[] = $overlapped;
            }
        }

        return $result;
    }

    /**
     * Estimación determinista: ceil(chars / 4).
     *
     * NO es un conteo exacto de tokens. Solo una aproximación documentada
     * para poblar knowledge_chunks.token_count mientras no se instale un
     * tokenizer real.
     */
    private function estimateTokens(string $text): int
    {
        $charCount = mb_strlen($text, 'UTF-8');

        return (int) ceil($charCount / 4);
    }
}
