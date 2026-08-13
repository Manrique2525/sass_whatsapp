<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Intentos de envío de mensajes por WhatsApp (FASE 6, ADR-029).
 *
 * Registra CADA llamada al provider (un intento = un intento). En esta fase el
 * envío se ejercita vía `WhatsAppMessagingService`; el job de cola con backoff
 * real llega con la fase de mensajería (FASE 9). `attempt`/`max_attempts`
 * registran el número de intento y el tope configurado.
 *
 * `payload` guarda el contenido del envío (sin secretos). `error_code` usa los
 * códigos WHATSAPP_* de dominio (p. ej. WHATSAPP_MESSAGE_FAILED).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_send_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('whatsapp_phone_number_id');
            $table->string('provider_message_id', 255)->nullable();
            $table->string('to', 40);
            $table->string('type', 20)->default('text');
            $table->json('payload')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('error_code', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->unsignedSmallInteger('max_attempts')->default(3);
            $table->timestamp('attempted_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('whatsapp_phone_number_id')->references('id')->on('whatsapp_phone_numbers')->cascadeOnDelete();
            $table->index(['tenant_id', 'status'], 'message_send_attempts_tenant_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_send_attempts');
    }
};
