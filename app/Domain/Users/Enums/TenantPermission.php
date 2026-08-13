<?php

declare(strict_types=1);

namespace App\Domain\Users\Enums;

/**
 * Permisos granulares por tenant (FASE 4).
 *
 * Cada permiso se evalúa SIEMPRE con: user + TenantContext + membresía + rol.
 * La matriz rol → permisos vive en `permissionsForRole()`; es la fuente de
 * verdad de autorización (los registros spatie se mantienen como espejo).
 *
 * No se crean permisos de dominios futuros (whatsapp, contacts, conversations,
 * chatbots, billing) — corresponden a fases posteriores.
 */
enum TenantPermission: string
{
    case ViewTenants = 'tenants.view';
    case UpdateTenants = 'tenants.update';

    case ViewUsers = 'users.view';
    case InviteUsers = 'users.invite';
    case UpdateUsers = 'users.update';
    case RemoveUsers = 'users.remove';

    case ViewRoles = 'roles.view';
    case AssignRoles = 'roles.assign';

    case ViewAgents = 'agents.view';
    case ManageAgents = 'agents.manage';

    case ViewAudit = 'audit.view';

    /**
     * Todos los permisos de la plataforma (para el seeder).
     *
     * @return list<TenantPermission>
     */
    public static function all(): array
    {
        return [
            self::ViewTenants,
            self::UpdateTenants,
            self::ViewUsers,
            self::InviteUsers,
            self::UpdateUsers,
            self::RemoveUsers,
            self::ViewRoles,
            self::AssignRoles,
            self::ViewAgents,
            self::ManageAgents,
            self::ViewAudit,
        ];
    }

    /**
     * Matriz rol (tenant) → permisos.
     *
     * - owner: administración completa del tenant (incluye assign roles y audit).
     * - admin: gestión operativa y de agentes; NO asigna roles ni audita.
     * - agent: solo lectura del tenant (operativo).
     *
     * @return list<TenantPermission>
     */
    public static function permissionsForRole(UserRole $role): array
    {
        return match ($role) {
            UserRole::Owner => self::all(),
            UserRole::Admin => [
                self::ViewTenants,
                self::ViewUsers,
                self::InviteUsers,
                self::UpdateUsers,
                self::RemoveUsers,
                self::ViewAgents,
                self::ManageAgents,
                self::ViewAudit,
            ],
            UserRole::Agent => [
                self::ViewTenants,
            ],
            // super_admin es rol global de plataforma (spatie, sin team):
            // no obtiene permisos de tenant, se autoriza aparte.
            UserRole::SuperAdmin => [],
        };
    }
}
