<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-conversation analytics metrics (FASE 21 U1, ADR-077).
 *
 * One row per conversation, populated when conversation reaches a terminal
 * state or periodically by aggregation job (U2).
 *
 * Privacy: stores ONLY durations, counts, and booleans.
 * NO PII: no message body, phone, email, AI prompts/responses.
 *
 * No soft deletes — historical record of conversation performance.
 * UNIQUE(tenant_id, conversation_id) enforces one metric row per conversation.
 *
 * Composite FK (tenant_id, conversation_id) → conversations(tenant_id, id)
 * follows the established pattern from conversation_assignments (FASE 15).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_metrics', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('conversation_id');

            // Timing
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->integer('response_time_seconds')->nullable();
            $table->integer('handle_time_seconds')->nullable();

            // Message counts
            $table->integer('message_count')->default(0);
            $table->integer('bot_message_count')->default(0);
            $table->integer('agent_message_count')->default(0);

            // Handoff
            $table->boolean('handoff_requested')->default(false);
            $table->timestamp('handoff_at')->nullable();

            $table->timestamps();

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();

            // Composite FK — follows conversation_assignments pattern (FASE 15)
            $table->foreign(['tenant_id', 'conversation_id'], 'conversation_metrics_tenant_conversation_foreign')
                ->references(['tenant_id', 'id'])
                ->on('conversations')
                ->cascadeOnDelete();

            $table->unique(
                ['tenant_id', 'conversation_id'],
                'conversation_metrics_tenant_conversation_unique',
            );

            $table->index(
                ['tenant_id', 'created_at'],
                'conversation_metrics_tenant_created_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_metrics');
    }
};
