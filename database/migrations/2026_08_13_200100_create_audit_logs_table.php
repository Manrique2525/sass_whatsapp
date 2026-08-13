<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registro de auditoría (prepara FASE 26).
     *
     * No es una tabla de dominio tenant (no lleva scope): se consulta por
     * `tenant_id` cuando hace falta, pero existe a nivel de plataforma.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->uuid('tenant_id')->nullable();
            $table->string('action', 100);
            $table->string('subject_type', 150)->nullable();
            $table->string('subject_id')->nullable();
            $table->json('data')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();

            $table->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();

            $table->index(['tenant_id', 'created_at'], 'audit_logs_tenant_created_index');
            $table->index('action', 'audit_logs_action_index');
            $table->index('subject_type', 'audit_logs_subject_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
