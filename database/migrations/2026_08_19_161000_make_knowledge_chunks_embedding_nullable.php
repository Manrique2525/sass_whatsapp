<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Make knowledge_chunks.embedding nullable (FASE 17 P0 fix).
 *
 * U2.3 creates chunks with embedding=NULL (text extraction + chunking).
 * U3 will materialize embeddings (AI provider).
 * NULL means "embedding pending/not yet generated".
 *
 * PostgreSQL: ALTER COLUMN DROP NOT NULL, preserving vector(1536) and HNSW index.
 * SQLite: no-op (embedding column does not exist).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'ALTER TABLE knowledge_chunks ALTER COLUMN embedding DROP NOT NULL'
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $nullCount = (int) DB::select(
            'SELECT COUNT(*) AS cnt FROM knowledge_chunks WHERE embedding IS NULL'
        )[0]->cnt;

        if ($nullCount > 0) {
            throw new RuntimeException(
                "Cannot revert embedding to NOT NULL: {$nullCount} chunk(s) have NULL embedding. "
                .'Populate all embeddings before running this rollback.'
            );
        }

        DB::statement(
            'ALTER TABLE knowledge_chunks ALTER COLUMN embedding SET NOT NULL'
        );
    }
};
