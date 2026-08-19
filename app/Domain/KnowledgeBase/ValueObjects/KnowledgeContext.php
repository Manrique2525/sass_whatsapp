<?php

declare(strict_types=1);

namespace App\Domain\KnowledgeBase\ValueObjects;

/**
 * Contexto RAG transportado entre AiNodeExecutor y AiPromptBuilder (FASE 17 U3.4).
 *
 * VO inmutable que encapsula el resultado de KnowledgeSearchService
 * para inyectarlo como contexto no confiable en el prompt.
 *
 * NO contiene embedding vectors. NO contiene API keys.
 * NO es confiable — los chunks son datos de terceros.
 */
final readonly class KnowledgeContext
{
    /**
     * @param  list<RetrievedChunk>  $chunks
     */
    public function __construct(
        public array $chunks,
        public int $totalCount,
        public float $searchDurationMs,
    ) {}

    public static function empty(): self
    {
        return new self(
            chunks: [],
            totalCount: 0,
            searchDurationMs: 0.0,
        );
    }

    public function isEmpty(): bool
    {
        return $this->totalCount === 0;
    }
}
