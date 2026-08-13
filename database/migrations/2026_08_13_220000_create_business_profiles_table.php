<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perfil de negocio del tenant (FASE 5, ADR-028).
 *
 * Relación 1:1 con `tenants`. El perfil existe desde la primera lectura
 * (el servicio lo crea si no existe), por lo que `tenant_id` es UNIQUE.
 *
 * `working_hours` es JSON con la forma `[{day, open, close, closed}, ...]`.
 * `logo` no se incluye en esta fase (requiere infraestructura de upload; se
 * añade cuando exista el módulo de storage/media).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name', 255)->nullable();
            $table->text('description')->nullable();
            $table->string('category', 100)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('website', 255)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('phone', 40)->nullable();
            $table->json('working_hours')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique('tenant_id', 'business_profiles_tenant_id_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_profiles');
    }
};
