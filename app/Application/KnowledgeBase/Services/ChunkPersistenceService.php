<?php

declare(strict_types=1);

namespace App\Application\KnowledgeBase\Services;

use App\Domain\KnowledgeBase\Models\KnowledgeChunk;
use App\Domain\KnowledgeBase\Models\KnowledgeDocument;
use App\Domain\KnowledgeBase\ValueObjects\TextChunk;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Materialización de chunks para un KnowledgeDocument (FASE 17 U2.3).
 *
 * Servicio explícito: reemplaza chunks derivados de un documento.
 * NO orchestration job. NO queue. NO embeddings.
 *
 * Garantías:
 * - Elimina chunks anteriores del documento
 * - Inserta nuevos con tenant_id server-side
 * - embedding = NULL (pre-embedding stage)
 * - document_id server-side
 * - chunk_index unique por documento
 */
final class ChunkPersistenceService
{
    /**
     * Reemplaza todos los chunks de un documento.
     *
     * Tenant y document se resuelven del modelo (server-side).
     * embedding queda NULL — U2.4 agregará embeddings.
     *
     * @param  TextChunk[]  $chunks
     */
    public function replaceChunks(KnowledgeDocument $document, array $chunks): int
    {
        return DB::transaction(function () use ($document, $chunks): int {
            KnowledgeChunk::query()
                ->withoutTenantScope()
                ->where('document_id', $document->id)
                ->delete();

            $tenantId = $document->tenant_id;
            $documentId = $document->id;
            $now = now();

            $rows = [];

            $hasEmbedding = Schema::hasColumn('knowledge_chunks', 'embedding');

            foreach ($chunks as $chunk) {
                $row = [
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $tenantId,
                    'document_id' => $documentId,
                    'content' => $chunk->content,
                    'token_count' => $chunk->tokenCount,
                    'chunk_index' => $chunk->chunkIndex,
                    'metadata' => $chunk->metadata !== [] ? json_encode($chunk->metadata) : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if ($hasEmbedding) {
                    $row['embedding'] = null;
                }

                $rows[] = $row;
            }

            if ($rows !== []) {
                KnowledgeChunk::query()
                    ->withoutTenantScope()
                    ->insert($rows);
            }

            return count($rows);
        });
    }
}
