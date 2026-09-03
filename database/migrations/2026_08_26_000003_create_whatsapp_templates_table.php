<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de templates de WhatsApp por cuenta/tenant (FASE 31 U5, ADR-121).
 *
 * La fuente de verdad del estado del catálogo es Meta (via sync); el SaaS lee,
 * sincroniza y ENVÍA templates aprobados, pero NUNCA los crea/propone en Meta.
 *
 * - `whatsapp_account_id` es NOT NULL: un template sincronizado/envariable tiene
 *   dueño bajo la cuenta WhatsApp del tenant (no se permiten catálogos huérfanos).
 * - Unicidad natural: `(whatsapp_account_id, name, language)` — identidad de
 *   catálogo (el mismo nombre en distinta cuenta/lenguaje es válido).
 * - Unicidad provider: `(whatsapp_account_id, provider_template_id)` — el sync
 *   de Meta no puede insertar duplicados de una plantilla externa.
 * - `status`/`category`/`components`: strings + json + cast a enum/value-object
 *   (driver-agnóstico, sin enums nativos de Postgres).
 * - `components` guarda schema NORMALIZADO (HEADER/BODY/FOOTER/BUTTONS), nunca
 *   estructura ejecutable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('whatsapp_account_id');
            $table->string('provider_template_id', 255)->nullable();
            $table->string('name', 255);
            $table->string('language', 20);
            $table->string('category', 50)->nullable();
            $table->string('status', 20)->default('unknown');
            $table->json('components')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();

            $table->foreign(
                ['tenant_id', 'whatsapp_account_id'],
                'whatsapp_templates_tenant_account_foreign',
            )
                ->references(['tenant_id', 'id'])
                ->on('whatsapp_accounts')
                ->cascadeOnDelete();

            $table->unique(
                ['whatsapp_account_id', 'name', 'language'],
                'whatsapp_templates_account_name_language_unique',
            );
            $table->unique(
                ['whatsapp_account_id', 'provider_template_id'],
                'whatsapp_templates_account_provider_unique',
            );
            $table->index(['tenant_id', 'status'], 'whatsapp_templates_tenant_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_templates');
    }
};
