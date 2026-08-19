<?php

declare(strict_types=1);

namespace App\Domain\KnowledgeBase\ValueObjects;

/**
 * Texto extraído de un documento knowledge (FASE 17 U2.3).
 *
 * VO puro: sin frameworks, sin DB, sin tenant awareness.
 * Creado por extractors, consumido por normalizer y chunker.
 */
final class ExtractedText
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $text,
        public readonly int $characterCount,
        public readonly array $metadata = [],
    ) {
        if ($characterCount < 0) {
            throw new \InvalidArgumentException('character_count must be non-negative.');
        }
    }
}
