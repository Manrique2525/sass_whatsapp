<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * En FASE 2 `tenant_users.tenant_id` quedó como bigint a la espera de la
     * tabla `tenants`. En FASE 3 `tenants.id` es UUID, por lo que se reconstruye
     * la columna como UUID y se añade la FK real.
     *
     * Es seguro porque aún no existen datos de tenant (no hay tabla `tenants`).
     */
    public function up(): void
    {
        Schema::table('tenant_users', function (Blueprint $table): void {
            $table->dropUnique('tenant_users_user_tenant_unique');
            $table->dropIndex('tenant_users_tenant_role_index');
            $table->dropColumn('tenant_id');

            $table->uuid('tenant_id')->after('id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['user_id', 'tenant_id'], 'tenant_users_user_tenant_unique');
            $table->index(['tenant_id', 'role'], 'tenant_users_tenant_role_index');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_users', function (Blueprint $table): void {
            $table->dropUnique('tenant_users_user_tenant_unique');
            $table->dropIndex('tenant_users_tenant_role_index');
            $table->dropForeign(['tenant_id']);
            $table->dropColumn('tenant_id');

            $table->unsignedBigInteger('tenant_id')->after('id');
            $table->unique(['user_id', 'tenant_id'], 'tenant_users_user_tenant_unique');
            $table->index(['tenant_id', 'role'], 'tenant_users_tenant_role_index');
        });
    }
};
