<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invitaciones a tenants (ADR-027).
 *
 * - El token real NUNCA se almacena: solo su hash (sha256) en `token_hash`.
 * - `status` permite invalidar (revoked) y garantizar no-reutilización
 *   (accepted). La expiración se verifica contra `expires_at` y se persiste
 *   como `expired` la primera vez que se detecta.
 * - `invited_by` registra quién invitó (actor).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('email');
            $table->string('role', 50);
            $table->string('token_hash', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'email'], 'tenant_invitations_tenant_email_index');
            $table->index('status', 'tenant_invitations_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_invitations');
    }
};
