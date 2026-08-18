<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Knowledge chunks (FASE 17, ADR-058).
 *
 * Cada fila almacena un fragmento de texto extraído de un documento y su
 * embedding vectorial para búsqueda semántica con pgvector.
 *
 * Dimensión fija: vector(1536) — contrato con text-embedding-3-small.
 * Si el modelo de embedding cambia en el futuro, se requiere una migración
 * explícita para alterar la dimensión de la columna.
 *
 * Sin soft delete: chunks son datos derivados regenerables. Al eliminar
 * el documento padre, CASCADE elimina todos sus chunks.
 *
 * `metadata` JSONB almacena provenance opcional (page, section, headers)
 * que se poblará en U2 durante el procesamiento de documentos.
 *
 * Índice HNSW para búsqueda de similaridad coseno (PostgreSQL only).
 * La columna vector y el índice HNSW se crean solo en PostgreSQL;
 * SQLite crea la tabla sin la columna vector para permitir model tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        $isPgsql = DB::connection()->getDriverName() === 'pgsql';

        Schema::create('knowledge_chunks', function (Blueprint $table) use ($isPgsql): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('document_id');
            $table->text('content');
            if ($isPgsql) {
                $table->vector('embedding', 1536);
            }
            $table->integer('token_count');
            $table->integer('chunk_index');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->index(['tenant_id', 'document_id'], 'knowledge_chunks_tenant_document_index');
        });

        if ($isPgsql) {
            DB::statement(
                'ALTER TABLE knowledge_chunks ADD CONSTRAINT knowledge_chunks_tenant_document_fk
                 FOREIGN KEY (tenant_id, document_id) REFERENCES knowledge_documents(tenant_id, id) ON DELETE CASCADE'
            );

            DB::statement(
                'CREATE UNIQUE INDEX knowledge_chunks_document_chunk_index_unique ON knowledge_chunks (document_id, chunk_index)'
            );

            DB::statement(
                'CREATE INDEX knowledge_chunks_embedding_idx ON knowledge_chunks USING hnsw (embedding vector_cosine_ops) WITH (m = 16, ef_construction = 64)'
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS knowledge_chunks_embedding_idx');
            DB::statement('DROP INDEX IF EXISTS knowledge_chunks_document_chunk_index_unique');
            DB::statement('ALTER TABLE knowledge_chunks DROP CONSTRAINT IF EXISTS knowledge_chunks_tenant_document_fk');
        }

        Schema::dropIfExists('knowledge_chunks');
    }
};
