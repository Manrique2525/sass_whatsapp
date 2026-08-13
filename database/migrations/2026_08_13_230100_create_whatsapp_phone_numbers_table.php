<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Números de WhatsApp conectados por tenant (FASE 6, ADR-029).
 *
 * `phone_id` es el `phone_number_id` de Meta: aparece en el webhook como
 * `entry[].changes[].value.metadata.phone_number_id` y es la CLAVE de
 * resolución de tenant (consulta indexada desde el webhook, sin contexto).
 * Meta asigna ids globalmente únicos, por eso `phone_id` es UNIQUE.
 *
 * `status` (connected/disconnected/banned) con string + cast a enum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_phone_numbers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('whatsapp_account_id')->nullable();
            $table->string('phone_id', 255);
            $table->string('display_phone_number', 40)->nullable();
            $table->string('verified_name', 255)->nullable();
            $table->string('quality_rating', 20)->nullable();
            $table->string('status', 20)->default('disconnected');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('whatsapp_account_id')->references('id')->on('whatsapp_accounts')->nullOnDelete();
            $table->unique('phone_id', 'whatsapp_phone_numbers_phone_id_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_phone_numbers');
    }
};
