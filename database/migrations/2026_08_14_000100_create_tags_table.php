<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etiquetas de contactos (FASE 7, preparado para FASE 20).
 *
 * Se crean la tabla y el modelo para que el CRM pueda etiquetar contactos en
 * fases posteriores; en FASE 7 NO hay API/UI de tags (solo relación N:M
 * `contact_tag`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name', 100);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'name'], 'tags_tenant_id_name_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tags');
    }
};
