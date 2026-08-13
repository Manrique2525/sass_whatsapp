<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Users\Enums\TenantPermission;
use App\Domain\Users\Enums\UserRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Roles y permisos base de la plataforma (definiciones globales en spatie).
 *
 * - `super_admin`: rol GLOBAL de plataforma (sin tenant; nunca se asigna desde
 *   un tenant).
 * - `owner`, `admin`, `agent`: roles por tenant. La fuente de verdad es la
 *   matriz `TenantPermission::permissionsForRole()` + `tenant_users.role`;
 *   los registros spatie son un espejo para `hasRole()`/`hasPermissionTo()`.
 *
 * Las definiciones de rol son GLOBALES (roles.tenant_id = NULL); las
 * asignaciones se scopean por tenant en el pivot `model_has_roles`.
 */
final class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [];

        foreach (TenantPermission::all() as $permission) {
            $permissions[$permission->value] = Permission::query()->firstOrCreate([
                'name' => $permission->value,
                'guard_name' => 'web',
            ]);
        }

        foreach (UserRole::cases() as $role) {
            /** @var Role $spatieRole */
            $spatieRole = Role::query()->firstOrCreate([
                'name' => $role->value,
                'guard_name' => 'web',
            ]);

            if ($role === UserRole::SuperAdmin) {
                $spatieRole->syncPermissions([]);

                continue;
            }

            $names = array_map(
                static fn (TenantPermission $permission): string => $permission->value,
                TenantPermission::permissionsForRole($role),
            );

            $spatieRole->syncPermissions($names);
        }
    }
}
