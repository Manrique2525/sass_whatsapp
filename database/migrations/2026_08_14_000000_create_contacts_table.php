<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contactos del CRM básico (FASE 7, ADR-030).
 *
 * `phone` se almacena en E.164 canónico con `+` inicial y sin separadores
 * (lo normaliza `ContactService::normalizePhone`). La unicidad es por tenant y
 * SOLO entre contactos activos: el índice único es parcial
 * (`WHERE deleted_at IS NULL`) para permitir re-crear el contacto tras un
 * soft delete.
 *
 * `metadata` es JSON libre del tenant (p. ej. custom fields, "llamado por
 * WhatsApp"). `provider_contact_id` prepara la correlación con el `wa_id` de
 * Meta (FASE 9, envío outbound).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('phone', 40);
            $table->string('name', 255);
            $table->string('email', 255)->nullable();
            $table->string('avatar_url', 2048)->nullable();
            $table->json('metadata')->nullable();
            $table->string('provider_contact_id', 255)->nullable();
            $table->timestamp('last_interaction_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'created_at'], 'contacts_tenant_id_created_at_index');
            $table->index(['tenant_id', 'name'], 'contacts_tenant_id_name_index');
        });

        DB::statement(
            'CREATE UNIQUE INDEX contacts_tenant_phone_unique ON contacts (tenant_id, phone) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropIndex('contacts_tenant_phone_unique');
        });

        Schema::dropIfExists('contacts');
    }
};
