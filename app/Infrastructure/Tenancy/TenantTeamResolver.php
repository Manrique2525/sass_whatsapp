<?php

declare(strict_types=1);

namespace App\Infrastructure\Tenancy;

use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Contracts\PermissionsTeamResolver;

/**
 * Resuelve el team id de spatie para las queries de roles/permisos (ADR-025).
 *
 * Orden de resolución:
 *  1. override explícito (`setPermissionsTeamId`) — usado para asignaciones
 *     globales de plataforma (team id GLOBAL de `UserRole::GLOBAL_TEAM_ID`).
 *  2. `TenantContext::id()` — team activo durante un request/job con contexto.
 *  3. `current_tenant_id` del usuario autenticado (fuera de middleware `tenant`,
 *     p. ej. `GET /auth/me`).
 *  4. `null` (default seguro: las queries con teams excluyen todo).
 *
 * Esto garantiza que `hasRole()`/`getRoleNames()`/`hasPermissionTo()` respeten
 * SIEMPRE el tenant activo. El rol global `super_admin` se evalúa aparte
 * (`User::isSuperAdmin()`), no mediante estas queries (ADR-026).
 */
final class TenantTeamResolver implements PermissionsTeamResolver
{
    private int|string|null $override = null;

    public function getPermissionsTeamId(): int|string|null
    {
        if ($this->override !== null) {
            return $this->override;
        }

        if (TenantContext::bound()) {
            return TenantContext::id();
        }

        $user = Auth::user();

        if ($user instanceof User) {
            return $user->current_tenant_id;
        }

        return null;
    }

    public function setPermissionsTeamId(int|string|Model|null $id): void
    {
        $this->override = $id instanceof Model ? $id->getKey() : $id;
    }

    /**
     * Restablece el override explícito y vuelve a la resolución automática.
     */
    public function forgetPermissionsTeamId(): void
    {
        $this->override = null;
    }
}
