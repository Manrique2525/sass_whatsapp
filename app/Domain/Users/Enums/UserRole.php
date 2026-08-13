<?php

declare(strict_types=1);

namespace App\Domain\Users\Enums;

/**
 * Roles de la plataforma.
 *
 * - `super_admin`: global de la plataforma (no está ligado a un tenant).
 * - `owner`, `admin`, `agent`: roles por tenant, asignados a través de
 *   `tenant_users` y materializados en spatie modo teams (`tenant_id`).
 */
enum UserRole: string
{
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

    public static function fromString(string $role): self
    {
        return self::from($role);
    }
}
