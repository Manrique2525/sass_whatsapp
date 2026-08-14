<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Traza de cada paso de una ejecución (FASE 11, ADR-036/037).
 *
 * Log inmutable (append-only, solo `created_at`) que registra cada nodo
 * visitado, cada evento del motor (step_started, step_completed, waiting,
 * sent, completed, failed, retry, error) y su payload. Es la base de la
 * auditoría/debugging del motor y del módulo de auditoría (FASE 26).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flow_execution_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('execution_id');
            $table->uuid('node_id')->nullable();
            $table->string('event', 50);
            $table->json('payload')->nullable();
            $table->integer('sequence')->default(0);
            $table->timestamp('created_at');

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('execution_id')->references('id')->on('flow_executions')->cascadeOnDelete();
            $table->foreign('node_id')->references('id')->on('flow_nodes')->nullOnDelete();

            $table->index(['execution_id', 'sequence'], 'flow_execution_logs_execution_sequence_index');
            $table->index(['execution_id', 'created_at'], 'flow_execution_logs_execution_created_at_index');
            $table->index(['tenant_id', 'execution_id'], 'flow_execution_logs_tenant_execution_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_execution_logs');
    }
};
