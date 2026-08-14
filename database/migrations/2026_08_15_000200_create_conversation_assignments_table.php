<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de asignaciones de una conversación (FASE 8, ADR-031).
 *
 * Cada fila registra una asignación manual o transferencia: agente asignado,
 * quien la realizó y la ventana temporal. `unassigned_at` se rellena cuando la
 * conversación se transfiere/reasigna (historial acumulativo). No se reutilizan
 * filas: cada asignación nueva inserta un registro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_assignments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('conversation_id');
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('unassigned_at')->nullable();
            $table->string('reason', 30)->default('manual');
            $table->timestamps();

            $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();
            $table->index(['conversation_id', 'assigned_at'], 'conversation_assignments_conversation_assigned_at_index');
            $table->index(['agent_id', 'assigned_at'], 'conversation_assignments_agent_assigned_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_assignments');
    }
};
