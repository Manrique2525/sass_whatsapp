<?php

declare(strict_types=1);

namespace App\Application\KnowledgeBase\Services;

use App\Application\KnowledgeBase\Contracts\KnowledgeSearchServiceInterface;
use App\Domain\AI\Contracts\EmbeddingProviderInterface;
use App\Domain\AI\ValueObjects\EmbeddingRequest;
use App\Domain\Billing\Contracts\UsageGuardInterface;
use App\Domain\Billing\Enums\UsageCategory;
use App\Domain\KnowledgeBase\Models\KnowledgeBase;
use App\Domain\KnowledgeBase\ValueObjects\KnowledgeSearchResult;
use App\Domain\KnowledgeBase\ValueObjects\RetrievedChunk;
use App\Domain\KnowledgeBase\ValueObjects\VectorSerializer;
use App\Domain\Tenants\Models\Tenant;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Servicio de búsqueda semántica tenant-scoped (FASE 17 U3.3).
 *
 * Pipeline: query → EmbeddingProviderInterface → pgvector cosine search → RetrievedChunk[]
 *
 * NO construye prompts. NO toca FlowEngine ni AiNodeExecutor.
 * NO crea endpoints HTTP. Consumido internamente por U3.4+.
 */
final class KnowledgeSearchService implements KnowledgeSearchServiceInterface
{
    public function __construct(
        private EmbeddingProviderInterface $embeddingProvider,
        private readonly UsageGuardInterface $usageGuard,
    ) {}

    /**
     * Ejecuta búsqueda semántica sobre knowledge_chunks de una knowledge base específica.
     *
     * @param  string  $tenantId  UUID del tenant autenticado
     * @param  string  $knowledgeBaseId  UUID de la knowledge base objetivo
     * @param  string  $query  Texto de búsqueda del usuario
     * @param  int|null  $topK  Número máximo de resultados (null = config default)
     * @param  float|null  $threshold  Umbral mínimo de similarity (null = sin filtro)
     *
     * @throws InvalidArgumentException si query inválida o topK fuera de rango
     */
    public function search(
        string $tenantId,
        string $knowledgeBaseId,
        string $query,
        ?int $topK = null,
        ?float $threshold = null,
    ): KnowledgeSearchResult {
        $this->validateQuery($query);

        $topK = $this->resolveTopK($topK);

        if ($threshold !== null) {
            $this->validateThreshold($threshold);
        }

        $resolvedThreshold = $threshold ?? config('knowledge.search.default_threshold');

        if (config('database.default') !== 'pgsql') {
            return new KnowledgeSearchResult(
                query: $query,
                chunks: [],
                totalCount: 0,
                topK: $topK,
                threshold: $resolvedThreshold,
                searchDurationMs: 0.0,
            );
        }

        $kb = $this->resolveKnowledgeBase($tenantId, $knowledgeBaseId);

        $queryVector = $this->embedQuery($query, $tenantId);

        $serializedQueryVector = VectorSerializer::serialize($queryVector);

        $searchStart = microtime(true);

        $rawResults = $this->executeCosineSearch(
            tenantId: $tenantId,
            knowledgeBaseId: $kb->id,
            serializedQueryVector: $serializedQueryVector,
            hardLimit: $topK + 1, // fetch one extra for threshold comparison
        );

        $searchDurationMs = round((microtime(true) - $searchStart) * 1000, 2);

        $filteredResults = $this->applyThreshold($rawResults, $resolvedThreshold);

        $limitedResults = array_slice($filteredResults, 0, $topK);

        $chunks = $this->mapToRetrievedChunks($limitedResults);

        $chunks = $this->applyContextLimit($chunks);

        return new KnowledgeSearchResult(
            query: $query,
            chunks: $chunks,
            totalCount: count($chunks),
            topK: $topK,
            threshold: $resolvedThreshold,
            searchDurationMs: $searchDurationMs,
        );
    }

    private function validateQuery(string $query): void
    {
        $trimmed = trim($query);

        if ($trimmed === '') {
            throw new InvalidArgumentException('Search query must not be empty.');
        }

        $maxLength = (int) config('knowledge.search.max_query_length', 2000);

        if (strlen($trimmed) > $maxLength) {
            throw new InvalidArgumentException(
                "Search query exceeds maximum length of {$maxLength} characters.",
            );
        }
    }

    private function resolveTopK(?int $topK): int
    {
        if ($topK === null) {
            return (int) config('knowledge.search.default_top_k', 5);
        }

        $hardMax = (int) config('knowledge.search.hard_max_top_k', 20);

        if ($topK < 1 || $topK > $hardMax) {
            throw new InvalidArgumentException(
                "topK must be between 1 and {$hardMax}, got {$topK}.",
            );
        }

        return $topK;
    }

    private function validateThreshold(float $threshold): void
    {
        if ($threshold < 0.0 || $threshold > 1.0) {
            throw new InvalidArgumentException(
                "Threshold must be between 0.0 and 1.0, got {$threshold}.",
            );
        }
    }

    private function resolveKnowledgeBase(string $tenantId, string $knowledgeBaseId): KnowledgeBase
    {
        $kb = KnowledgeBase::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('id', $knowledgeBaseId)
            ->whereNull('deleted_at')
            ->first();

        if ($kb === null) {
            throw new InvalidArgumentException('Knowledge base not found or access denied.');
        }

        return $kb;
    }

    /** @return list<float> */
    private function embedQuery(string $query, string $tenantId): array
    {
        $estimatedTokens = max(1, (int) ceil(mb_strlen($query) / 3));
        $reservation = null;

        try {
            $tenant = Tenant::query()->find($tenantId);

            if ($tenant !== null) {
                $reservation = $this->usageGuard->reserve(
                    tenant: $tenant,
                    category: UsageCategory::AiTokens,
                    quantity: $estimatedTokens,
                    ttlSeconds: 120,
                );
            }

            $response = $this->embeddingProvider->embed(
                new EmbeddingRequest(input: [$query]),
            );

            if ($reservation !== null) {
                $this->usageGuard->commitWithActual($reservation, $response->totalInputTokens);
            } elseif ($tenant !== null && $response->totalInputTokens > 0) {
                try {
                    $this->usageGuard->recordDirect(
                        tenant: $tenant,
                        category: UsageCategory::AiTokens,
                        quantity: $response->totalInputTokens,
                        description: 'rag_embedding_unlimited',
                    );
                } catch (\Throwable) {
                    // Unlimited telemetry is best-effort
                }
            }

            $vector = $response->embeddings[0] ?? null;

            if ($vector === null) {
                throw new \RuntimeException('Embedding provider returned empty response for query.');
            }

            VectorSerializer::validate($vector);

            return $vector;
        } catch (\Throwable $e) {
            if ($reservation !== null) {
                try {
                    $this->usageGuard->release($reservation);
                } catch (\Throwable) {
                    // Release failure is logged internally by UsageGuard
                }
            }

            throw $e;
        }
    }

    /**
     * Ejecuta la query cosine search con SQL parametrizado.
     *
     * @return list<array{chunk_id: string, document_id: string, content: string, chunk_index: int, similarity: float}>
     */
    private function executeCosineSearch(
        string $tenantId,
        string $knowledgeBaseId,
        string $serializedQueryVector,
        int $hardLimit,
    ): array {
        $results = DB::select(
            '
            SELECT
                kc.id AS chunk_id,
                kc.document_id,
                kc.content,
                kc.chunk_index,
                (1 - (kc.embedding <=> ?::vector)) AS similarity
            FROM knowledge_chunks kc
            INNER JOIN knowledge_documents kd ON kd.id = kc.document_id
            WHERE kc.tenant_id = ?
              AND kc.embedding IS NOT NULL
              AND kd.knowledge_base_id = ?
              AND kd.deleted_at IS NULL
            ORDER BY kc.embedding <=> ?::vector ASC, kc.document_id, kc.chunk_index, kc.id
            LIMIT ?
            ',
            [
                $serializedQueryVector,
                $tenantId,
                $knowledgeBaseId,
                $serializedQueryVector,
                $hardLimit,
            ],
        );

        return $results;
    }

    /**
     * @param  list<array{chunk_id: string, document_id: string, content: string, chunk_index: int, similarity: float}>  $results
     * @return list<array{chunk_id: string, document_id: string, content: string, chunk_index: int, similarity: float}>
     */
    private function applyThreshold(array $results, ?float $threshold): array
    {
        if ($threshold === null) {
            return $results;
        }

        return array_values(array_filter(
            $results,
            fn (array $row): bool => $row['similarity'] >= $threshold,
        ));
    }

    /**
     * @param  list<array{chunk_id: string, document_id: string, content: string, chunk_index: int, similarity: float}>  $rows
     * @return list<RetrievedChunk>
     */
    private function mapToRetrievedChunks(array $rows): array
    {
        return array_map(
            fn (array $row): RetrievedChunk => new RetrievedChunk(
                chunkId: $row['chunk_id'],
                documentId: $row['document_id'],
                content: $row['content'],
                score: (float) $row['similarity'],
                metadata: ['chunk_index' => $row['chunk_index']],
            ),
            $rows,
        );
    }

    /**
     * Aplica límite de caracteres aggregate al contenido de los chunks.
     *
     * No corta chunks a mitad. Detiene inclusión cuando el siguiente chunk excede max_chars.
     *
     * @param  list<RetrievedChunk>  $chunks
     * @return list<RetrievedChunk>
     */
    private function applyContextLimit(array $chunks): array
    {
        $maxChars = (int) config('knowledge.search.max_context_chars', 15000);
        $result = [];
        $totalChars = 0;

        foreach ($chunks as $chunk) {
            $chunkLen = strlen($chunk->content);

            if ($totalChars + $chunkLen > $maxChars) {
                break;
            }

            $result[] = $chunk;
            $totalChars += $chunkLen;
        }

        return $result;
    }
}
