<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Users\Enums\UserRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Crea los roles base de la plataforma (definiciones globales).
 *
 * - `super_admin`: rol global (sin tenant).
 * - `owner`, `admin`, `agent`: roles por tenant; su asignación se materializa
 *   en FASE 3 con spatie en modo teams (`tenant_id`).
 */
final class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (UserRole::cases() as $role) {
            Role::query()->firstOrCreate([
                'name' => $role->value,
                'guard_name' => 'web',
            ]);
        }
    }
}
