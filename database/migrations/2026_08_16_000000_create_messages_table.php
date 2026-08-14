<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mensajes de una conversación (FASE 9, ADR-032).
 *
 * Cada mensaje entrante (webhook) o saliente (envío asíncrono) del tenant.
 * La idempotencia del inbound se protege con el índice UNIQUE parcial
 * `(tenant_id, provider_message_id) WHERE provider_message_id IS NOT NULL`
 * (el mismo `messages[].id` de Meta no debe persistirse dos veces).
 *
 * Los status de Meta (sent/delivered/read/failed) se reflejan en
 * `status` + su columna temporal correspondiente; un status update NUNCA crea
 * un mensaje: actualiza la fila por `provider_message_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('conversation_id');
            $table->string('provider_message_id', 255)->nullable();
            $table->string('direction', 10);
            $table->string('type', 20);
            $table->string('status', 20);
            $table->text('body')->nullable();
            $table->string('media_url', 2048)->nullable();
            $table->string('media_mime', 100)->nullable();
            $table->bigInteger('media_size')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();

            $table->unique(['tenant_id', 'provider_message_id'], 'messages_tenant_provider_message_unique');
            $table->index(['tenant_id', 'conversation_id', 'created_at'], 'messages_tenant_conversation_created_at_index');
            $table->index(['conversation_id'], 'messages_conversation_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
