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
 *
 * FASE 15 añade `conversations.claim` para que cualquier miembro activo pueda
 * reclamarse a sí mismo una conversación que espera atención humana.
 *
 * FASE 17 U2.1 añade el dominio knowledge: `knowledge.view` (todos los roles,
 * listar y ver KBs/documentos) y `knowledge.manage` (owner/admin: crear,
 * editar y eliminar KBs y documentos).
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
    case ClaimConversations = 'conversations.claim';

    case SendMessages = 'messages.send';

    case ViewFlows = 'flows.view';
    case ManageFlows = 'flows.manage';

    case ViewKnowledge = 'knowledge.view';
    case ManageKnowledge = 'knowledge.manage';

    case ViewFaqs = 'faqs.view';
    case ManageFaqs = 'faqs.manage';

    case ViewLeads = 'leads.view';
    case ManageLeads = 'leads.manage';

    case ViewTags = 'tags.view';
    case ManageTags = 'tags.manage';

    case ViewAnalytics = 'analytics.view';

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
            self::ClaimConversations,
            self::SendMessages,
            self::ViewFlows,
            self::ManageFlows,
            self::ViewKnowledge,
            self::ManageKnowledge,
            self::ViewFaqs,
            self::ManageFaqs,
            self::ViewLeads,
            self::ManageLeads,
            self::ViewTags,
            self::ManageTags,
            self::ViewAnalytics,
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
     * - conversations: view y claim propio para todos (inbox); manage y assign
     *   solo owner/admin (estados, bot, asignación/transferencia a agentes).
     * - messages: send para todos los roles del tenant (responder en el chat).
     * - flows: view para todos (leer flujos/chatbots/ejecuciones); manage solo
     *   owner/admin (crear/editar/publicar/desactivar flujos y triggers).
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
                self::ClaimConversations,
                self::SendMessages,
                self::ViewFlows,
                self::ManageFlows,
                self::ViewKnowledge,
                self::ManageKnowledge,
                self::ViewFaqs,
                self::ManageFaqs,
                self::ViewLeads,
                self::ManageLeads,
                self::ViewTags,
                self::ManageTags,
                self::ViewAnalytics,
            ],
            UserRole::Agent => [
                self::ViewTenants,
                self::ViewBusinessProfile,
                self::ViewWhatsApp,
                self::ViewContacts,
                self::ViewConversations,
                self::ClaimConversations,
                self::SendMessages,
                self::ViewFlows,
                self::ViewKnowledge,
                self::ViewFaqs,
                self::ViewLeads,
                self::ViewTags,
            ],
            // super_admin es rol global de plataforma (spatie, sin team):
            // no obtiene permisos de tenant, se autoriza aparte.
            UserRole::SuperAdmin => [],
        };
    }
}
