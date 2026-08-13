<?php

declare(strict_types=1);

namespace App\Application\Users\Services;

use App\Domain\Tenants\Enums\TenantStatus;
use App\Domain\Tenants\Exceptions\PermissionDeniedException;
use App\Domain\Tenants\Exceptions\TenantMembershipException;
use App\Domain\Tenants\Exceptions\TenantNotActiveException;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\TenantPermission;
use App\Domain\Users\Models\User;

/**
 * Autorización central de acciones por tenant (ADR-026).
 *
 * Pipeline: usuario autenticado → tenant activo + pertenencia (tenant_users) →
 * rol → permiso de la matriz `TenantPermission`. La fuente de verdad es la
 * matriz de código, no los registros spatie (estos son espejo para hasRole()).
 *
 * Los controllers NUNCA deciden autorización: siempre pasan por aquí (o por los
 * policies que delegan en este servicio). Sin membresía/tenant inactivo → 404/409
 * (ocultar existencia, ADR-010/023); sin permiso → 403 PERMISSION_DENIED.
 */
final class AuthorizationService
{
    public function authorize(User $user, TenantPermission $permission, Tenant $tenant): void
    {
        if ($tenant->status !== TenantStatus::Active) {
            throw new TenantNotActiveException('El tenant no está activo.');
        }

        if (! $user->isCurrentTenant($tenant)) {
            throw new TenantMembershipException('El tenant no es el activo del usuario.');
        }

        $role = $user->roleForTenant($tenant->id);

        if ($role === null) {
            throw new TenantMembershipException('El usuario no es miembro activo del tenant.');
        }

        if (! in_array($permission, TenantPermission::permissionsForRole($role), true)) {
            throw new PermissionDeniedException("Permiso denegado: {$permission->value}.");
        }
    }

    public function can(User $user, TenantPermission $permission, Tenant $tenant): bool
    {
        try {
            $this->authorize($user, $permission, $tenant);

            return true;
        } catch (TenantMembershipException|TenantNotActiveException|PermissionDeniedException) {
            return false;
        }
    }

    /**
     * Permisos concedidos al usuario en el tenant (para `me()`/frontend).
     *
     * @return list<string>
     */
    public function permissionsForTenant(User $user, Tenant $tenant): array
    {
        if ($tenant->status !== TenantStatus::Active) {
            return [];
        }

        $role = $user->roleForTenant($tenant->id);

        if ($role === null) {
            return [];
        }

        return array_map(
            static fn (TenantPermission $permission): string => $permission->value,
            TenantPermission::permissionsForRole($role),
        );
    }
}
