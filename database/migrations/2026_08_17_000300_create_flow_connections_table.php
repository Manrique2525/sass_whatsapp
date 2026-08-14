<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aristas dirigidas de un flujo (FASE 11).
 *
 * Cada conexión va `source_node_id → target_node_id`. `label` es el resultado
 * de rama: las condiciones (`condition`) usan labels `true`/`false`; el resto
 * de nodos tienen una única arista saliente sin label. El `FlowValidator`
 * garantiza determinismo (un nodo no-waiting con varias aristas sin label es
 * `FLOW_INVALID`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flow_connections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('flow_id');
            $table->uuid('source_node_id');
            $table->uuid('target_node_id');
            $table->string('label', 255)->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('flow_id')->references('id')->on('flows')->cascadeOnDelete();
            $table->foreign('source_node_id')->references('id')->on('flow_nodes')->cascadeOnDelete();
            $table->foreign('target_node_id')->references('id')->on('flow_nodes')->cascadeOnDelete();

            $table->index(['flow_id'], 'flow_connections_flow_index');
            $table->index(['source_node_id'], 'flow_connections_source_index');
            $table->index(['target_node_id'], 'flow_connections_target_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_connections');
    }
};
