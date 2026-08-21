<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-scoped notifications (FASE 22 U1, ADR-082).
 *
 * Persistent in-app notifications per tenant. Supports both targeted
 * (user_id != NULL) and tenant-wide (user_id = NULL) notifications.
 *
 * Privacy: title/body are plain text. data JSON contains ONLY safe
 * metadata (IDs, event types, route hints). NO PII: no phone, email,
 * message body, AI prompt/response, API keys, credentials.
 *
 * Soft deletes enabled: user can dismiss notifications without losing
 * audit trail. History preserved for compliance.
 *
 * FK user_id → users: SET NULL (preserve notification history if user
 * leaves). FK tenant_id → tenants: CASCADE (tenant data dies with tenant).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->unsignedBigInteger('user_id')->nullable();

            $table->string('type', 100);
            $table->string('priority', 20)->default('normal');
            $table->string('title', 255);
            $table->text('body');
            $table->json('data')->nullable();

            $table->timestamp('read_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // FK: tenant (cascade delete)
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();

            // FK: user (set null on delete — preserve notification history)
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            // Primary query pattern: list notifications for user in tenant
            $table->index(
                ['tenant_id', 'user_id', 'read_at'],
                'notifications_tenant_user_read_index',
            );

            // Timeline: newest first
            $table->index(
                ['tenant_id', 'created_at'],
                'notifications_tenant_created_index',
            );

            // Filter by type
            $table->index(
                ['tenant_id', 'type'],
                'notifications_tenant_type_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
