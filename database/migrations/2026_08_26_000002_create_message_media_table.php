<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asset de media de un mensaje (FASE 31 U5, ADR-121).
 *
 * Modela el PROCESSING y ALMACENAMIENTO interno de un media (inbound descargado
 * desde Meta, y outbound referenciado) desacoplado del mensaje:
 *
 * - CANONICAL internal storage: `storage_disk` + `storage_path` (path opaco
 *   generado por la app, NUNCA derivado del nombre de archivo del usuario).
 * - `provider_media_id`: id de media de Meta; el lookup/descarga se resuelve a
 *   través del provider (nunca se acepta una URL arbitraria del cliente).
 * - `sha256`/`mime`/`size`: valores VALIDADOS del contenido real descargado
 *   (no del header/webhook/filename).
 * - `processing_status`: pending/processing/downloaded/failed (string + cast,
 *   driver-agnóstico, sin enum nativo de Postgres).
 * - `failure_reason`: código seguro (oversize/invalid_mime/ssrf_rejected/
 *   download_failed/provider_not_found), nunca body crudo ni contenido.
 *
 * `direction`/`type` NO se duplican aquí: se derivan del `Message` padre para
 * evitar que divergan (invariante de consistencia, ver ADR-121).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_media', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('message_id');
            $table->string('provider_media_id', 255)->nullable();
            $table->string('storage_disk', 50)->nullable();
            $table->string('storage_path', 1024)->nullable();
            $table->string('sha256', 64)->nullable();
            $table->string('mime', 100)->nullable();
            $table->bigInteger('size')->nullable();
            $table->string('original_filename', 255)->nullable();
            $table->string('processing_status', 20)->default('pending');
            $table->string('failure_reason', 100)->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();

            $table->foreign(
                ['tenant_id', 'message_id'],
                'message_media_tenant_id_message_id_foreign',
            )
                ->references(['tenant_id', 'id'])
                ->on('messages')
                ->cascadeOnDelete();

            $table->unique(['tenant_id', 'message_id'], 'message_media_tenant_message_unique');
            $table->unique(['tenant_id', 'provider_media_id'], 'message_media_tenant_provider_media_unique');
            $table->index(['tenant_id', 'processing_status'], 'message_media_tenant_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_media');
    }
};
