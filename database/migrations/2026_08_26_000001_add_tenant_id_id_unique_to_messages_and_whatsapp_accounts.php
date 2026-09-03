<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Claves candidatas compuestas `UNIQUE (tenant_id, id)` en tablas padre
 * (FASE 31 U5, ADR-121).
 *
 * Las FKs compuestas tenant-aware requieren una clave candidata correspondiente
 * en la tabla referenciada. `messages` y `whatsapp_accounts` solo tenían su PK
 * `id` (UUID globalmente único), por lo que se añade `UNIQUE (tenant_id, id)`
 * a ambas para que los hijos `message_media` y `whatsapp_templates` puedan
 * referenciar `(tenant_id, id)` y así el aislamiento tenant se imponga a nivel
 * de base de datos (no solo por aplicación).
 *
 * Es un índice de soporte de integridad sin reescritura de datos: como `id` ya
 * es globalmente único (UUID PK), no puede haber filas en conflicto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'messages_tenant_id_id_unique');
        });

        Schema::table('whatsapp_accounts', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'whatsapp_accounts_tenant_id_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropUnique('messages_tenant_id_id_unique');
        });

        Schema::table('whatsapp_accounts', function (Blueprint $table): void {
            $table->dropUnique('whatsapp_accounts_tenant_id_id_unique');
        });
    }
};
