<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Eventos crudos recibidos de Meta (FASE 6, ADR-029).
 *
 * Tabla de PLATAFORMA (no lleva scope de tenant): un mismo evento de Meta es
 * único a nivel global y puede llegar sin que el tenant esté aún resuelto.
 *
 * - `provider_event_id` UNIQUE = id de Meta (`messages[].id` / `statuses[].id`):
 *   es el dedupe real (insert con ON CONFLICT DO NOTHING cubre duplicados
 *   secuenciales y concurrentes).
 * - `tenant_id` nullable: se rellena al resolver `metadata.phone_number_id`.
 * - `status` (received/enqueued/processed/failed) y `event_type`
 *   (message/status) como string + cast a enum (patrón del repo).
 * - `duplicate` = true si Meta re-envió un evento ya registrado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider_event_id', 255);
            $table->uuid('tenant_id')->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 20)->default('received');
            $table->string('event_type', 20)->nullable();
            $table->boolean('duplicate')->default(false);
            $table->string('error_code', 100)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique('provider_event_id', 'webhook_events_provider_event_id_unique');
            $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
            $table->index(['status', 'created_at'], 'webhook_events_status_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
