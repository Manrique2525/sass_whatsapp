<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Disparadores de flujo (FASE 11, `docs/chatbot-engine.md` §6).
 *
 * FASE 11 usa `keyword` (palabra clave en el primer mensaje), `new_message`
 * (cualquier mensaje entrante) y `start` (primer mensaje de una conversación).
 * `tag`/`schedule`/`webhook` llegan en FASE 14; el `type` ya está registrado.
 *
 * `keyword` guarda el patrón de disparo; `config` guarda extras futuros
 * (cron, secretos de webhook). `priority` ordena triggers del mismo tipo;
 * `active` permite desactivar sin eliminar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('triggers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('flow_id');
            $table->string('type', 20);
            $table->string('keyword', 255)->nullable();
            $table->json('config')->nullable();
            $table->integer('priority')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('flow_id')->references('id')->on('flows')->cascadeOnDelete();

            $table->index(['flow_id'], 'triggers_flow_index');
            $table->index(['tenant_id', 'type', 'active'], 'triggers_tenant_type_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('triggers');
    }
};
