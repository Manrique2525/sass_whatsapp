<?php

declare(strict_types=1);

namespace App\Domain\Users\Enums;

/**
 * Roles de la plataforma.
 *
 * - `super_admin`: rol GLOBAL de plataforma (no ligado a un tenant). Vive en el
 *   pivot `model_has_roles` con `tenant_id = GLOBAL_TEAM_ID` (sentinel UUID),
 *   nunca se asigna desde un tenant y jamás concede acceso cross-tenant por
 *   queries normales (ADR-025/026).
 * - `owner`, `admin`, `agent`: roles por tenant. La fuente de verdad es el pivot
 *   `tenant_users.role`; se materializan en spatie modo teams (`tenant_id`)
 *   como espejo para `hasRole()`/`getRoleNames()`.
 */
enum UserRole: string
{
    /**
     * Team id "global" de spatie (sentinel UUID válido para la columna uuid).
     * Los roles de plataforma se asignan con este team id en `model_has_roles`.
     */
    public const GLOBAL_TEAM_ID = '00000000-0000-0000-0000-000000000000';

    case SuperAdmin = 'super_admin';
    case Owner = 'owner';
    case Admin = 'admin';
    case Agent = 'agent';

    /**
     * Roles que solo existen en el contexto de un tenant.
     *
     * @return list<UserRole>
     */
    public static function tenantRoles(): array
    {
        return [self::Owner, self::Admin, self::Agent];
    }

    /**
     * Roles que se pueden asignar/invitar desde el panel de un tenant.
     *
     * @return list<UserRole>
     */
    public static function assignableTenantRoles(): array
    {
        return [self::Admin, self::Agent];
    }

    public static function fromString(string $role): self
    {
        return self::from($role);
    }
}
