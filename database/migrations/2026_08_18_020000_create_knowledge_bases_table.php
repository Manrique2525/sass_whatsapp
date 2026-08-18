<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Knowledge bases (FASE 17, ADR-058).
 *
 * Cada knowledge base agrupa documentos de un tenant para alimentar el
 * contexto RAG del nodo AI. Un tenant puede crear varias KBs; cada nodo
 * AI apunta a una KB específica.
 *
 * Soft delete para preservar la referencia en ejecuciones de flujos
 * históricos que pudieran apuntar a una KB eliminada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_bases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        DB::statement(
            'CREATE UNIQUE INDEX knowledge_bases_tenant_name_unique ON knowledge_bases (tenant_id, name) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::table('knowledge_bases', function (Blueprint $table): void {
            $table->dropIndex('knowledge_bases_tenant_name_unique');
        });

        Schema::dropIfExists('knowledge_bases');
    }
};
