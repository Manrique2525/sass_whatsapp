<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conversaciones del inbox del tenant (FASE 8, ADR-031).
 *
 * Una conversación agrupa los mensajes de un contacto con el negocio. Nace en
 * `open` (alta manual o, en FASE 9, desde el webhook de WhatsApp). `status`
 * sigue la máquina de estados de `ConversationStatus` (open/pending/resolved/
 * archived). `agent_id` es la asignación VIGENTE a un agente; el historial de
 * asignaciones vive en `conversation_assignments`.
 *
 * `context` es JSON libre del motor de flujos (FASE 10+). `flow_execution_id`
 * queda nullable SIN FK hasta que exista la tabla de ejecuciones.
 *
 * Tipos: `tenant_id`/`contact_id` son UUID (tenants/contacts); `agent_id`
 * referencia `users.id` (BIGINT), igual que `tenant_users.user_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('contact_id');
            $table->string('status', 20)->default('open');
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('last_interaction_at')->nullable();
            $table->foreignId('agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('auto_assigned')->default(false);
            $table->boolean('bot_paused')->default(false);
            $table->json('context')->nullable();
            $table->uuid('flow_execution_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('contact_id')->references('id')->on('contacts')->cascadeOnDelete();

            $table->index(['tenant_id', 'status', 'last_message_at'], 'conversations_tenant_status_last_message_at_index');
            $table->index(['tenant_id', 'contact_id'], 'conversations_tenant_contact_index');
            $table->index(['tenant_id', 'agent_id'], 'conversations_tenant_agent_index');
            $table->index(['tenant_id', 'last_interaction_at'], 'conversations_tenant_last_interaction_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
