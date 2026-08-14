<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flujos del chatbot (FASE 11, ADR-034).
 *
 * NO existe tabla `flow_versions`: la propia fila es la versión. Solo el flujo
 * `published` dispara y se ejecuta; `draft` se edita y `inactive` está pausado.
 * `config` es JSON libre del motor (p. ej. `max_steps` límite de nodos por
 * ejecución); soft delete para no romper historiales al "eliminar".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flows', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('chatbot_id');
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft');
            $table->json('config')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('chatbot_id')->references('id')->on('chatbots')->cascadeOnDelete();

            $table->index(['tenant_id', 'status'], 'flows_tenant_status_index');
            $table->index(['chatbot_id'], 'flows_chatbot_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flows');
    }
};
