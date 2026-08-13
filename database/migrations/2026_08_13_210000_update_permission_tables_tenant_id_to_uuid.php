<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Convierte las columnas de team de spatie (teams) a UUID para alinearlas con
 * `tenants.id` (que es UUID). Antes eran `unsignedBigInteger`, incompatibles.
 *
 * PostgreSQL no castea bigint→uuid automáticamente, así que la conversión usa
 * `USING tenant_id::text::uuid` (NULL → NULL). Las tablas spatie están vacías
 * en el estado documentado (ADR-025), por lo que el casteo es seguro.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::table('roles', function (Blueprint $table): void {
            $table->dropIndex('roles_team_foreign_key_index');
            $table->dropUnique('roles_tenant_id_name_guard_name_unique');
        });

        $this->alterTenantIdToUuid('roles', $driver, nullable: true);

        Schema::table('roles', function (Blueprint $table): void {
            $table->index('tenant_id', 'roles_team_foreign_key_index');
            $table->unique(['tenant_id', 'name', 'guard_name'], 'roles_tenant_id_name_guard_name_unique');
        });

        // model_has_roles.tenant_id (NOT NULL) — team por asignación.
        Schema::table('model_has_roles', function (Blueprint $table): void {
            $table->dropPrimary('model_has_roles_role_model_type_primary');
            $table->dropIndex('model_has_roles_team_foreign_key_index');
        });

        $this->alterTenantIdToUuid('model_has_roles', $driver, nullable: false);

        Schema::table('model_has_roles', function (Blueprint $table): void {
            $table->index('tenant_id', 'model_has_roles_team_foreign_key_index');
            $table->primary(
                ['tenant_id', 'role_id', 'model_id', 'model_type'],
                'model_has_roles_role_model_type_primary',
            );
        });

        // model_has_permissions.tenant_id (NOT NULL) — team por asignación.
        Schema::table('model_has_permissions', function (Blueprint $table): void {
            $table->dropPrimary('model_has_permissions_permission_model_type_primary');
            $table->dropIndex('model_has_permissions_team_foreign_key_index');
        });

        $this->alterTenantIdToUuid('model_has_permissions', $driver, nullable: false);

        Schema::table('model_has_permissions', function (Blueprint $table): void {
            $table->index('tenant_id', 'model_has_permissions_team_foreign_key_index');
            $table->primary(
                ['tenant_id', 'permission_id', 'model_id', 'model_type'],
                'model_has_permissions_permission_model_type_primary',
            );
        });
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::table('roles', function (Blueprint $table): void {
            $table->dropIndex('roles_team_foreign_key_index');
            $table->dropUnique('roles_tenant_id_name_guard_name_unique');
        });

        $this->alterTenantIdToBigint('roles', $driver, nullable: true);

        Schema::table('roles', function (Blueprint $table): void {
            $table->index('tenant_id', 'roles_team_foreign_key_index');
            $table->unique(['tenant_id', 'name', 'guard_name'], 'roles_tenant_id_name_guard_name_unique');
        });

        Schema::table('model_has_roles', function (Blueprint $table): void {
            $table->dropPrimary('model_has_roles_role_model_type_primary');
            $table->dropIndex('model_has_roles_team_foreign_key_index');
        });

        $this->alterTenantIdToBigint('model_has_roles', $driver, nullable: false);

        Schema::table('model_has_roles', function (Blueprint $table): void {
            $table->index('tenant_id', 'model_has_roles_team_foreign_key_index');
            $table->primary(
                ['tenant_id', 'role_id', 'model_id', 'model_type'],
                'model_has_roles_role_model_type_primary',
            );
        });

        Schema::table('model_has_permissions', function (Blueprint $table): void {
            $table->dropPrimary('model_has_permissions_permission_model_type_primary');
            $table->dropIndex('model_has_permissions_team_foreign_key_index');
        });

        $this->alterTenantIdToBigint('model_has_permissions', $driver, nullable: false);

        Schema::table('model_has_permissions', function (Blueprint $table): void {
            $table->index('tenant_id', 'model_has_permissions_team_foreign_key_index');
            $table->primary(
                ['tenant_id', 'permission_id', 'model_id', 'model_type'],
                'model_has_permissions_permission_model_type_primary',
            );
        });
    }

    private function alterTenantIdToUuid(string $table, string $driver, bool $nullable): void
    {
        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN tenant_id TYPE uuid USING tenant_id::text::uuid");
            DB::statement("ALTER TABLE {$table} ALTER COLUMN tenant_id ".($nullable ? 'DROP NOT NULL' : 'SET NOT NULL'));

            return;
        }

        if ($nullable) {
            Schema::table($table, function (Blueprint $t): void {
                $t->uuid('tenant_id')->nullable()->change();
            });
        } else {
            Schema::table($table, function (Blueprint $t): void {
                $t->uuid('tenant_id')->change();
            });
        }
    }

    private function alterTenantIdToBigint(string $table, string $driver, bool $nullable): void
    {
        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN tenant_id TYPE bigint USING tenant_id::text::bigint");
            DB::statement("ALTER TABLE {$table} ALTER COLUMN tenant_id ".($nullable ? 'DROP NOT NULL' : 'SET NOT NULL'));

            return;
        }

        if ($nullable) {
            Schema::table($table, function (Blueprint $t): void {
                $t->unsignedBigInteger('tenant_id')->nullable()->change();
            });
        } else {
            Schema::table($table, function (Blueprint $t): void {
                $t->unsignedBigInteger('tenant_id')->change();
            });
        }
    }
};
