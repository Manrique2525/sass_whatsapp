<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Leads (FASE 19, ADR-072).
 *
 * CRM básico multi-tenant: leads capturados manualmente.
 * phone/email se almacenan normalizados (LeadPhoneNormalizer/LeadEmailNormalizer).
 *
 * Sin UNIQUE en phone ni email — la deduplicación es de aplicación (U2).
 * Partial indexes en PostgreSQL para phone/email activos.
 *
 * CHECK constraints PostgreSQL:
 * - status IN ('new','contacted','qualified','won','lost')
 * - LENGTH(TRIM(name)) > 0
 */
return new class extends Migration
{
    public function up(): void
    {
        $isPgsql = DB::connection()->getDriverName() === 'pgsql';

        Schema::create('leads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name', 255);
            $table->string('phone', 30)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('status', 20)->default('new');
            $table->string('source', 50)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->index(['tenant_id', 'status'], 'leads_tenant_status_index');
            $table->index(['tenant_id', 'created_at'], 'leads_tenant_created_index');
        });

        if ($isPgsql) {
            DB::statement(
                'CREATE INDEX leads_tenant_phone_index ON leads (tenant_id, phone) WHERE phone IS NOT NULL AND deleted_at IS NULL'
            );
            DB::statement(
                'CREATE INDEX leads_tenant_email_index ON leads (tenant_id, email) WHERE email IS NOT NULL AND deleted_at IS NULL'
            );
            DB::statement(
                "ALTER TABLE leads ADD CONSTRAINT leads_status_check CHECK (status IN ('new','contacted','qualified','won','lost'))"
            );
            DB::statement(
                'ALTER TABLE leads ADD CONSTRAINT leads_name_not_empty CHECK (LENGTH(TRIM(name)) > 0)'
            );
        } else {
            Schema::table('leads', function (Blueprint $table): void {
                $table->index(['tenant_id', 'phone'], 'leads_tenant_phone_index');
                $table->index(['tenant_id', 'email'], 'leads_tenant_email_index');
            });
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            Schema::table('leads', function (Blueprint $table): void {
                $table->dropIndex('leads_tenant_phone_index');
                $table->dropIndex('leads_tenant_email_index');
            });
        }

        Schema::dropIfExists('leads');
    }
};
