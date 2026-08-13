<?php

declare(strict_types=1);

namespace App\Application\Users\Services;

use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Models\User;
use Spatie\Permission\PermissionRegistrar;

/**
 * Materializa el rol de `tenant_users` (fuente de verdad) en spatie modo teams
 * (espejo), para que `hasRole()`/`getRoleNames()` respondan según el tenant
 * activo (ADR-025/026).
 *
 * Las asignaciones spatie se hacen SIEMPRE con el override del resolver al
 * `tenant_id` correspondiente y se restablece en `finally`; así el pivot
 * `model_has_roles.tenant_id` queda correctamente scopeado y nada se filtra a
 * otros tenants.
 */
final class TenantRoleManager
{
    public function __construct(private readonly PermissionRegistrar $registrar) {}

    public function syncRoles(User $user, Tenant $tenant, UserRole $role): void
    {
        $this->registrar->setPermissionsTeamId($tenant->id);

        try {
            // syncRoles (no assignRole): reemplaza los roles del equipo por el
            // nuevo, manteniendo el espejo exacto de `tenant_users.role`.
            $user->syncRoles([$role->value]);
            $user->unsetRelation('roles');
        } finally {
            $this->registrar->setPermissionsTeamId(null);
        }
    }

    /**
     * Elimina TODOS los roles spatie del usuario para el tenant indicado.
     */
    public function revokeRoles(User $user, Tenant $tenant): void
    {
        $this->registrar->setPermissionsTeamId($tenant->id);

        try {
            $user->syncRoles([]);
            $user->unsetRelation('roles');
        } finally {
            $this->registrar->setPermissionsTeamId(null);
        }
    }

    /**
     * Asigna el rol GLOBAL de plataforma (team sentinel). Solo para uso del
     * panel de plataforma (super_admin); nunca desde controllers de tenant.
     */
    public function assignGlobalRole(User $user, UserRole $role): void
    {
        $this->registrar->setPermissionsTeamId(UserRole::GLOBAL_TEAM_ID);

        try {
            $user->assignRole($role->value);
            $user->unsetRelation('roles');
        } finally {
            $this->registrar->setPermissionsTeamId(null);
        }
    }
}
