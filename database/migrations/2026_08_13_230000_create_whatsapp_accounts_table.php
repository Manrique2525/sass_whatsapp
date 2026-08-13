<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuenta de WhatsApp Business (WABA) conectada por tenant (FASE 6, ADR-029).
 *
 * Un tenant conecta UNA WABA con su propio access token, guardado CIFRADO
 * (atributo `encrypted` en el modelo, cifrado con la APP_KEY). El token nunca
 * se devuelve por API y es el que usa el envío de ese tenant.
 *
 * `status` se guarda como string + cast a enum (patrón del repo, evita enums
 * nativos de Postgres y es driver-agnóstico).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('whatsapp_business_account_id', 255)->nullable();
            $table->string('display_name', 255)->nullable();
            $table->text('access_token')->nullable();
            $table->string('status', 20)->default('disconnected');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique('tenant_id', 'whatsapp_accounts_tenant_id_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_accounts');
    }
};
