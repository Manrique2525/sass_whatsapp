<?php

declare(strict_types=1);

namespace App\Domain\KnowledgeBase\ValueObjects;

/**
 * Chunk de texto determinista (FASE 17 U2.3).
 *
 * VO puro: sin frameworks, sin DB, sin embeddings.
 * Generado por DocumentChunker, materializado por replaceChunks.
 */
final class TextChunk
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $content,
        public readonly int $chunkIndex,
        public readonly int $tokenCount,
        public readonly array $metadata = [],
    ) {
        if ($chunkIndex < 0) {
            throw new \InvalidArgumentException('chunk_index must be non-negative.');
        }
        if ($tokenCount < 0) {
            throw new \InvalidArgumentException('token_count must be non-negative.');
        }
        if ($content === '') {
            throw new \InvalidArgumentException('content must not be empty.');
        }
    }
}
