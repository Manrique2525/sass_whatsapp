<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FAQs (FASE 18, ADR-069).
 *
 * Preguntas frecuentes curadas por el tenant con respuestas textuales
 * deterministas. Matching exacto normalizado (sin fuzzy, sin embeddings).
 *
 * normalized_question es la representación canónica para unicidad y matching.
 * Generada por FaqQuestionNormalizer: trim, lowercase, NFC, edge punctuation
 * removal, whitespace collapse. Preserva acentos y ñ.
 *
 * PostgreSQL: partial unique index WHERE deleted_at IS NULL permite recrear
 * una pregunta eliminada. SQLite: unique index estándar (sin predicate).
 */
return new class extends Migration
{
    public function up(): void
    {
        $isPgsql = DB::connection()->getDriverName() === 'pgsql';

        Schema::create('faqs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('question', 500);
            $table->string('normalized_question', 500);
            $table->text('answer');
            $table->string('status', 20)->default('active');
            $table->integer('priority')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->index(['tenant_id', 'status'], 'faqs_tenant_status_index');
        });

        if ($isPgsql) {
            DB::statement(
                'CREATE UNIQUE INDEX faqs_tenant_normalized_question_unique ON faqs (tenant_id, normalized_question) WHERE deleted_at IS NULL'
            );
        } else {
            Schema::table('faqs', function (Blueprint $table): void {
                $table->unique(['tenant_id', 'normalized_question']);
            });
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            Schema::table('faqs', function (Blueprint $table): void {
                $table->dropIndex('faqs_tenant_normalized_question_unique');
            });
        }

        Schema::dropIfExists('faqs');
    }
};
