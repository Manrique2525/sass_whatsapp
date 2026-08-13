<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabla raíz del multi-tenancy.
     *
     * `id` es UUID (generado por el modelo con HasUuids). `plan_id` aún no tiene
     * FK (los planes llegan en FASE 23 - Billing). `status` es un valor de
     * `App\Domain\Tenants\Enums\TenantStatus`.
     */
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status', 20)->default('active');
            $table->uuid('plan_id')->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->string('locale', 16)->default('en');
            $table->timestamps();

            $table->index('status', 'tenants_status_index');
            $table->index('plan_id', 'tenants_plan_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
