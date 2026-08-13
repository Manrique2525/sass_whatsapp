<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Añade los campos de membresía a `tenant_users`: estado del miembro
 * (active/invited/disabled), `invited_at` y `joined_at` (ADR-026).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_users', function (Blueprint $table): void {
            $table->string('status', 20)->default('active')->after('role');
            $table->timestamp('invited_at')->nullable()->after('status');
            $table->timestamp('joined_at')->nullable()->after('invited_at');
        });

        Schema::table('tenant_users', function (Blueprint $table): void {
            $table->index(['tenant_id', 'status'], 'tenant_users_tenant_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_users', function (Blueprint $table): void {
            $table->dropIndex('tenant_users_tenant_status_index');
        });

        Schema::table('tenant_users', function (Blueprint $table): void {
            $table->dropColumn(['status', 'invited_at', 'joined_at']);
        });
    }
};
