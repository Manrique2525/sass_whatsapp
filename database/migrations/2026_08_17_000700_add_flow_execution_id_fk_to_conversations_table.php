<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FK de `conversations.flow_execution_id` → `flow_executions` (FASE 11).
 *
 * Cierra el pendiente de FASE 8 (ADR-031): la columna existía nullable SIN FK
 * porque la tabla de ejecuciones llegaba en FASE 11. `nullOnDelete`: si se
 * purga una ejecución, la conversación deja de referenciarla (no se bloquea la
 * eliminación).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->foreign('flow_execution_id')->references('id')->on('flow_executions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropForeign(['flow_execution_id']);
        });
    }
};
