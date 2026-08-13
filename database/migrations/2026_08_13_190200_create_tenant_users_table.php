<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pivot usuario <-> tenant.
     *
     * Nota: la FK hacia `tenants` se añade en FASE 3, cuando exista la tabla
     * `tenants`. Aquí ya queda el índice y la unicidad (user_id, tenant_id).
     */
    public function up(): void
    {
        Schema::create('tenant_users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 50);

            $table->unique(['user_id', 'tenant_id'], 'tenant_users_user_tenant_unique');
            $table->index(['tenant_id', 'role'], 'tenant_users_tenant_role_index');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_users');
    }
};
