<?php

declare(strict_types=1);

namespace App\Application\KnowledgeBase\Services;

use App\Application\Audit\Services\AuditLogger;
use App\Domain\AI\Contracts\EmbeddingProviderInterface;
use App\Domain\AI\ValueObjects\EmbeddingRequest;
use App\Domain\KnowledgeBase\Models\KnowledgeChunk;
use App\Domain\KnowledgeBase\Models\KnowledgeDocument;
use App\Domain\KnowledgeBase\ValueObjects\VectorSerializer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Materialización de embeddings vectoriales para chunks knowledge (FASE 17 U3.2).
 *
 * Orquesta: pending chunks → batching → provider → validate → persist.
 *
 * Invariantes:
 * - Solo procesa chunks con embedding IS NULL (idempotencia).
 * - CAS update: WHERE embedding IS NULL previene sobrescritura.
 * - Batch completo en DB transaction: todo o nada.
 * - Revalida documento activo antes de cada batch (delete-during-processing).
 * - No crea contenido ni toca el pipeline de extracción.
 * - Documento permanece en estado Ready (embedding es etapa separada).
 */
final class EmbeddingMaterializationService
{
    public function __construct(
        private readonly EmbeddingProviderInterface $provider,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Materializa embeddings para todos los chunks pending de un documento.
     *
     * @return array{chunks_processed: int, batches: int, total_input_tokens: int}
     */
    public function materialize(KnowledgeDocument $document): array
    {
        $startTime = microtime(true);

        $chunksProcessed = 0;
        $batches = 0;
        $totalTokens = 0;

        $hasEmbeddingColumn = Schema::hasColumn('knowledge_chunks', 'embedding');

        if (! $hasEmbeddingColumn) {
            return ['chunks_processed' => 0, 'batches' => 0, 'total_input_tokens' => 0];
        }

        $maxBatchSize = (int) config('ai.embedding.providers.openai.max_batch_size', 50);

        while (true) {
            if ($this->isDocumentDeleted($document)) {
                break;
            }

            $pendingChunks = $this->loadPendingChunks($document);

            if ($pendingChunks === []) {
                break;
            }

            $batch = array_slice($pendingChunks, 0, $maxBatchSize);

            $result = $this->processBatch($document, $batch);

            $chunksProcessed += $result['processed'];
            $totalTokens += $result['tokens'];
            $batches++;

            if ($result['processed'] < count($batch)) {
                break;
            }
        }

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        $this->auditLogger->record(
            action: 'knowledge_embeddings.materialized',
            data: [
                'document_id' => $document->id,
                'knowledge_base_id' => $document->knowledge_base_id,
                'chunks_processed' => $chunksProcessed,
                'batches' => $batches,
                'total_input_tokens' => $totalTokens,
                'duration_ms' => $durationMs,
                'success' => true,
            ],
            subjectType: KnowledgeDocument::class,
            subjectId: $document->id,
            tenantId: $document->tenant_id,
        );

        return [
            'chunks_processed' => $chunksProcessed,
            'batches' => $batches,
            'total_input_tokens' => $totalTokens,
        ];
    }

    /**
     * Procesa un batch de chunks: embed → validate → persist.
     *
     * @param  KnowledgeChunk[]  $batch
     * @return array{processed: int, tokens: int}
     */
    private function processBatch(KnowledgeDocument $document, array $batch): array
    {
        $inputTexts = array_map(
            fn (KnowledgeChunk $chunk): string => $chunk->content,
            $batch,
        );

        $request = new EmbeddingRequest(input: $inputTexts);

        $response = $this->provider->embed($request);

        if (count($response->embeddings) !== count($batch)) {
            return ['processed' => 0, 'tokens' => 0];
        }

        $persisted = $this->persistBatch($document, $batch, $response->embeddings);

        return [
            'processed' => $persisted,
            'tokens' => $response->totalInputTokens,
        ];
    }

    /**
     * Persiste embeddings de un batch usando CAS + DB transaction.
     *
     * @param  KnowledgeChunk[]  $chunks
     * @param  list<list<float>>  $embeddings
     */
    private function persistBatch(KnowledgeDocument $document, array $chunks, array $embeddings): int
    {
        $now = now()->toDateTimeString();

        return (int) DB::transaction(function () use ($document, $chunks, $embeddings, $now): int {
            $persisted = 0;

            foreach ($chunks as $index => $chunk) {
                $serialized = VectorSerializer::serialize($embeddings[$index]);

                $updated = DB::update(
                    'UPDATE knowledge_chunks SET embedding = ?::vector, updated_at = ? WHERE id = ? AND embedding IS NULL AND tenant_id = ? AND document_id = ?',
                    [$serialized, $now, $chunk->id, $document->tenant_id, $document->id],
                );

                $persisted += $updated;
            }

            return $persisted;
        });
    }

    /**
     * @return KnowledgeChunk[]
     */
    private function loadPendingChunks(KnowledgeDocument $document): array
    {
        return KnowledgeChunk::query()
            ->withoutTenantScope()
            ->where('tenant_id', $document->tenant_id)
            ->where('document_id', $document->id)
            ->whereNull('embedding')
            ->orderBy('chunk_index')
            ->limit((int) config('ai.embedding.providers.openai.max_batch_size', 50))
            ->get()
            ->all();
    }

    private function isDocumentDeleted(KnowledgeDocument $document): bool
    {
        return KnowledgeDocument::query()
            ->withoutTenantScope()
            ->where('id', $document->id)
            ->where('tenant_id', $document->tenant_id)
            ->whereNull('deleted_at')
            ->doesntExist();
    }
}
