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
 * No se crean permisos de dominios futuros (conversations, chatbots, billing)
 * — corresponden a fases posteriores.
 *
 * FASE 5 añade el dominio business_profile (view para todos los roles del
 * tenant; update solo owner/admin).
 *
 * FASE 6 añade el dominio whatsapp: `whatsapp.view` (todos los roles, estado de
 * la conexión) y `whatsapp.manage` (owner/admin: conectar/desconectar y enviar).
 *
 * FASE 7 añade el dominio contacts: `contacts.view` (todos los roles, listar y
 * ver contactos) y `contacts.manage` (owner/admin: crear, editar y eliminar).
 *
 * FASE 8 añade el dominio conversations: `conversations.view` (todos los
 * roles, inbox de conversaciones), `conversations.manage` (owner/admin:
 * crear/actualizar/cerrar/reabrir y pausar bot) y `conversations.assign`
 * (owner/admin: asignar/transferir a agentes).
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

    case ViewBusinessProfile = 'business_profile.view';
    case UpdateBusinessProfile = 'business_profile.update';

    case ViewWhatsApp = 'whatsapp.view';
    case ManageWhatsApp = 'whatsapp.manage';

    case ViewContacts = 'contacts.view';
    case ManageContacts = 'contacts.manage';

    case ViewConversations = 'conversations.view';
    case ManageConversations = 'conversations.manage';
    case AssignConversations = 'conversations.assign';

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
            self::ViewBusinessProfile,
            self::UpdateBusinessProfile,
            self::ViewWhatsApp,
            self::ManageWhatsApp,
            self::ViewContacts,
            self::ManageContacts,
            self::ViewConversations,
            self::ManageConversations,
            self::AssignConversations,
        ];
    }

    /**
     * Matriz rol (tenant) → permisos.
     *
     * - owner: administración completa del tenant (incluye assign roles y audit).
     * - admin: gestión operativa y de agentes; NO asigna roles ni audita.
     * - agent: solo lectura del tenant (operativo).
     * - business_profile: view para todos; update solo owner/admin.
     * - whatsapp: view para todos (estado); manage solo owner/admin
     *   (conectar/desconectar y envío).
     * - contacts: view para todos (CRM); manage solo owner/admin
     *   (crear/editar/eliminar contactos).
     * - conversations: view para todos (inbox); manage y assign solo owner/admin
     *   (estados, bot, asignación/transferencia a agentes).
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
                self::ViewBusinessProfile,
                self::UpdateBusinessProfile,
                self::ViewWhatsApp,
                self::ManageWhatsApp,
                self::ViewContacts,
                self::ManageContacts,
                self::ViewConversations,
                self::ManageConversations,
                self::AssignConversations,
            ],
            UserRole::Agent => [
                self::ViewTenants,
                self::ViewBusinessProfile,
                self::ViewWhatsApp,
                self::ViewContacts,
                self::ViewConversations,
            ],
            // super_admin es rol global de plataforma (spatie, sin team):
            // no obtiene permisos de tenant, se autoriza aparte.
            UserRole::SuperAdmin => [],
        };
    }
}
