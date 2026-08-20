<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily analytics aggregates (FASE 21 U1, ADR-077).
 *
 * Pre-computed daily rollup per tenant. One row per (tenant_id, date).
 * Populated by AggregateDailyAnalyticsJob (U2, not yet implemented).
 *
 * Privacy: stores ONLY aggregated counters, durations, and booleans.
 * NO PII: no phone, email, name, message body, AI prompt/response, API keys.
 *
 * No soft deletes — append-only aggregation table.
 * UNIQUE(tenant_id, date) enforces at most one rollup per tenant per day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_daily', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->date('date');

            // Message metrics
            $table->integer('total_messages')->default(0);
            $table->integer('messages_inbound')->default(0);
            $table->integer('messages_outbound')->default(0);
            $table->integer('messages_delivered')->default(0);
            $table->integer('messages_read')->default(0);
            $table->integer('messages_failed')->default(0);

            // Conversation metrics
            $table->integer('total_conversations')->default(0);
            $table->integer('conversations_open')->default(0);
            $table->integer('conversations_resolved')->default(0);
            $table->integer('conversations_archived')->default(0);
            $table->integer('conversations_handoff_requested')->default(0);
            $table->integer('conversations_bot_paused')->default(0);

            // Contact metrics
            $table->integer('unique_contacts')->default(0);

            // Response time (NULL = insufficient data)
            $table->integer('avg_response_time_seconds')->nullable();

            // Flow execution metrics
            $table->integer('total_flow_executions')->default(0);
            $table->integer('flow_executions_completed')->default(0);
            $table->integer('flow_executions_failed')->default(0);

            // Lead metrics
            $table->integer('total_leads')->default(0);
            $table->integer('leads_new')->default(0);
            $table->integer('leads_won')->default(0);
            $table->integer('leads_lost')->default(0);

            // AI metrics
            $table->bigInteger('total_ai_tokens')->default(0);

            $table->timestamps();

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();

            $table->unique(['tenant_id', 'date'], 'analytics_daily_tenant_date_unique');
            $table->index(['tenant_id', 'date'], 'analytics_daily_tenant_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_daily');
    }
};
