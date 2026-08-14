<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ejecuciones de flujo (FASE 11, ADR-037).
 *
 * Una ejecución activa por conversación: UNIQUE parcial
 * `(tenant_id, conversation_id) WHERE status IN ('running','waiting')` es la
 * barrera de concurrencia a nivel de base de datos (el motor además usa lock
 * Redis por conversación y CAS de avance de nodo).
 *
 * `current_node_id` apunta al nodo en curso (null al terminar); `variables`
 * persiste las variables de la ejecución (`{{custom.*}}`, respuestas de
 * `question`, etc.). `last_inbound_message_id` es la barrera de idempotencia
 * del motor: el mismo inbound jamás avanza dos veces la ejecución.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flow_executions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('flow_id');
            $table->uuid('conversation_id');
            $table->uuid('current_node_id')->nullable();
            $table->string('status', 20)->default('running');
            $table->json('variables')->default('{}');
            $table->integer('attempts')->default(0);
            $table->uuid('last_inbound_message_id')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('flow_id')->references('id')->on('flows')->cascadeOnDelete();
            $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();
            $table->foreign('current_node_id')->references('id')->on('flow_nodes')->nullOnDelete();
            $table->foreign('last_inbound_message_id')->references('id')->on('messages')->nullOnDelete();

            $table->index(['flow_id'], 'flow_executions_flow_index');
            $table->index(['status', 'created_at'], 'flow_executions_status_created_at_index');
        });

        // UNIQUE parcial `(tenant_id, conversation_id) WHERE status IN
        // ('running','waiting')` → una sola ejecución activa por conversación.
        // Laravel 12 no soporta `where()` fluido en índices: SQL nativo
        // (precedente FASE 7, contacts).
        DB::statement(
            "CREATE UNIQUE INDEX flow_executions_active_conversation_unique ON flow_executions (tenant_id, conversation_id) WHERE status in ('running', 'waiting')"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS flow_executions_active_conversation_unique');

        Schema::dropIfExists('flow_executions');
    }
};
