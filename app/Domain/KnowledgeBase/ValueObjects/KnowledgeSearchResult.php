<?php

declare(strict_types=1);

namespace App\Domain\KnowledgeBase\ValueObjects;

/**
 * Value object inmutable que encapsula el resultado de una búsqueda semántica (FASE 17 U3.3).
 *
 * Contiene la lista ordenada de chunks recuperados, el query utilizado,
 * y metadatos de la operación (threshold usado, total encontrado, duración).
 */
final readonly class KnowledgeSearchResult
{
    /**
     * @param  list<RetrievedChunk>  $chunks
     */
    public function __construct(
        public string $query,
        /** @var list<RetrievedChunk> */
        public array $chunks,
        public int $totalCount,
        public int $topK,
        public ?float $threshold,
        public float $searchDurationMs,
    ) {}

    public function isEmpty(): bool
    {
        return $this->totalCount === 0;
    }

    public function totalContentLength(): int
    {
        return array_sum(array_map(
            fn (RetrievedChunk $chunk): int => strlen($chunk->content),
            $this->chunks,
        ));
    }

    /**
     * @return array{query: string, chunks: list<array{chunk_id: string, document_id: string, content: string, score: float, metadata: array<string, mixed>}>, total_count: int, top_k: int, threshold: float|null, search_duration_ms: float}
     */
    public function toArray(): array
    {
        return [
            'query' => $this->query,
            'chunks' => array_map(
                fn (RetrievedChunk $chunk): array => $chunk->toArray(),
                $this->chunks,
            ),
            'total_count' => $this->totalCount,
            'top_k' => $this->topK,
            'threshold' => $this->threshold,
            'search_duration_ms' => $this->searchDurationMs,
        ];
    }
}
