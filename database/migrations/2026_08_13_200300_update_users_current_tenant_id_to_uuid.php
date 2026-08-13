<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Convierte `users.current_tenant_id` a UUID y añade la FK hacia `tenants`.
     *
     * Seguro porque no hay tenants reales todavía (la columna es nullable y no
     * existen datos de tenant previos).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_current_tenant_id_index');
            $table->dropColumn('current_tenant_id');

            $table->uuid('current_tenant_id')->nullable()->after('email_verified_at');
            $table->foreign('current_tenant_id')->references('id')->on('tenants')->nullOnDelete();
            $table->index('current_tenant_id', 'users_current_tenant_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_current_tenant_id_index');
            $table->dropForeign(['current_tenant_id']);
            $table->dropColumn('current_tenant_id');

            $table->unsignedBigInteger('current_tenant_id')->nullable()->after('email_verified_at');
            $table->index('current_tenant_id', 'users_current_tenant_id_index');
        });
    }
};
