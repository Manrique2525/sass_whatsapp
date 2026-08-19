<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Application\KnowledgeBase\Contracts\KnowledgeSearchServiceInterface;
use App\Domain\KnowledgeBase\ValueObjects\KnowledgeSearchResult;
use App\Domain\KnowledgeBase\ValueObjects\RetrievedChunk;

/**
 * Fake KnowledgeSearchService for testing RAG context injection (FASE 17 U3.4).
 *
 * Permite configurar resultados de búsqueda controlados sin PostgreSQL.
 */
class FakeKnowledgeSearchService implements KnowledgeSearchServiceInterface
{
    private ?KnowledgeSearchResult $fixedResult = null;

    private int $callCount = 0;

    /** @var list<array{tenantId: string, knowledgeBaseId: string, query: string}> */
    private array $capturedCalls = [];

    public function withResult(KnowledgeSearchResult $result): self
    {
        $this->fixedResult = $result;

        return $this;
    }

    public function withEmptyResult(): self
    {
        $this->fixedResult = new KnowledgeSearchResult(
            query: '',
            chunks: [],
            totalCount: 0,
            topK: 5,
            threshold: null,
            searchDurationMs: 0.0,
        );

        return $this;
    }

    public function search(
        string $tenantId,
        string $knowledgeBaseId,
        string $query,
        ?int $topK = null,
        ?float $threshold = null,
    ): KnowledgeSearchResult {
        $this->callCount++;
        $this->capturedCalls[] = [
            'tenantId' => $tenantId,
            'knowledgeBaseId' => $knowledgeBaseId,
            'query' => $query,
        ];

        if ($this->fixedResult !== null) {
            return $this->fixedResult;
        }

        return new KnowledgeSearchResult(
            query: $query,
            chunks: [],
            totalCount: 0,
            topK: $topK ?? 5,
            threshold: $threshold,
            searchDurationMs: 0.0,
        );
    }

    public function callCount(): int
    {
        return $this->callCount;
    }

    /** @return list<array{tenantId: string, knowledgeBaseId: string, query: string}> */
    public function capturedCalls(): array
    {
        return $this->capturedCalls;
    }

    public function lastCall(): ?array
    {
        return end($this->capturedCalls) ?: null;
    }

    public static function chunks(array $contents): KnowledgeSearchResult
    {
        $chunks = [];
        foreach ($contents as $i => $content) {
            $chunks[] = new RetrievedChunk(
                chunkId: "chunk-{$i}",
                documentId: "doc-{$i}",
                content: $content,
                score: 0.9 - ($i * 0.1),
                metadata: ['chunk_index' => $i],
            );
        }

        return new KnowledgeSearchResult(
            query: '',
            chunks: $chunks,
            totalCount: count($chunks),
            topK: 5,
            threshold: null,
            searchDurationMs: 10.0,
        );
    }
}
