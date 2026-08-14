<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chatbots del tenant (FASE 11).
 *
 * Un chatbot agrupa flujos (`flows`). En FASE 11 solo se modela el contenedor:
 * la activación/desactivación ocurre a nivel de flujo (`published`/`inactive`).
 * Soft delete: eliminar un chatbot oculta sus flujos sin borrarlos del disco
 * (los flujos se borran en cascada solo si se purga la fila).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->index(['tenant_id', 'created_at'], 'chatbots_tenant_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbots');
    }
};
