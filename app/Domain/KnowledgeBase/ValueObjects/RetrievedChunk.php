<?php

declare(strict_types=1);

namespace App\Domain\KnowledgeBase\ValueObjects;

/**
 * Value object inmutable que representa un chunk recuperado por búsqueda semántica (FASE 17 U3.3).
 *
 * Es el resultado interno de KnowledgeSearchService.
 * No expone Eloquent models. No serializa a API response.
 * U3.4 consumirá content para inyectar en prompts.
 */
final readonly class RetrievedChunk
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $chunkId,
        public string $documentId,
        public string $content,
        public float $score,
        public array $metadata,
    ) {}

    /**
     * @return array{chunk_id: string, document_id: string, content: string, score: float, metadata: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'chunk_id' => $this->chunkId,
            'document_id' => $this->documentId,
            'content' => $this->content,
            'score' => $this->score,
            'metadata' => $this->metadata,
        ];
    }
}
