<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Knowledge documents (FASE 17, ADR-058).
 *
 * Registra cada archivo subido a una knowledge base. El binario se almacena
 * en S3/MinIO (U2); esta tabla guarda la metadata: ubicación, tamaño,
 * hash, estado del procesamiento y estadísticas de chunking.
 *
 * `file_hash` (SHA-256 del contenido) permite detectar duplicados. El
 * índice único parcial previene dos documentos idénticos dentro de la
 * misma KB activa, pero permite re-subir tras un soft delete.
 *
 * FK compuesta (tenant_id, knowledge_base_id) garantiza a nivel DB que un
 * documento no pueda apuntar a una KB de otro tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('knowledge_base_id');
            $table->string('original_filename', 500);
            $table->string('storage_disk', 50)->default('minio');
            $table->string('storage_path', 1000);
            $table->string('mime_type', 100);
            $table->bigInteger('file_size');
            $table->char('file_hash', 64);
            $table->string('status', 20)->default('uploaded');
            $table->text('error_message')->nullable();
            $table->integer('chunk_count')->nullable();
            $table->integer('total_tokens')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('knowledge_base_id')->references('id')->on('knowledge_bases')->cascadeOnDelete();

            $table->index(['tenant_id', 'knowledge_base_id'], 'knowledge_documents_tenant_kb_index');
            $table->index(['tenant_id', 'status'], 'knowledge_documents_tenant_status_index');
        });

        DB::statement(
            'CREATE UNIQUE INDEX knowledge_documents_tenant_kb_hash_unique ON knowledge_documents (tenant_id, knowledge_base_id, file_hash) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table): void {
            $table->dropIndex('knowledge_documents_tenant_kb_hash_unique');
        });

        Schema::dropIfExists('knowledge_documents');
    }
};
