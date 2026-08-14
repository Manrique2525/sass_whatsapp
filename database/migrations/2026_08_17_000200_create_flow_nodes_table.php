<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nodos de un flujo (FASE 11, `docs/database.md`).
 *
 * `type` es el tipo de nodo (message/buttons/question/condition/delay/tag/
 * webhook/ai/human/end), `config` su contenido específico (texto, botones,
 * condición, prompt IA...) y `position_x`/`position_y` la posición del editor
 * visual (FASE 12).
 *
 * Constraint: UNIQUE parcial `(flow_id) WHERE is_start = true` → un solo nodo
 * de entrada por flujo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flow_nodes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('flow_id');
            $table->string('type', 20);
            $table->string('name', 255);
            $table->integer('position_x')->default(0);
            $table->integer('position_y')->default(0);
            $table->json('config')->nullable();
            $table->boolean('is_start')->default(false);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('flow_id')->references('id')->on('flows')->cascadeOnDelete();

            $table->index(['flow_id'], 'flow_nodes_flow_index');
            $table->index(['tenant_id', 'flow_id'], 'flow_nodes_tenant_flow_index');
        });

        // UNIQUE parcial `(flow_id) WHERE is_start = true` → un solo nodo de
        // entrada por flujo. Laravel 12 no soporta `where()` fluido en índices:
        // se crea con SQL nativo (precedente FASE 7, contacts).
        DB::statement(
            'CREATE UNIQUE INDEX flow_nodes_flow_is_start_unique ON flow_nodes (flow_id) WHERE is_start = true'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS flow_nodes_flow_is_start_unique');

        Schema::dropIfExists('flow_nodes');
    }
};
