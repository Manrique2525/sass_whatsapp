<?php

declare(strict_types=1);

namespace App\Application\Contacts\Services;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Tenants\Models\Tenant;

/**
 * Resuelve la conversación más reciente de un contacto dentro de un tenant
 * para el contexto de tag assignment (FASE 20 U3).
 *
 * Política:
 * - Si el caller provee una conversación explícita (TagNodeExecutor),
 *   se usa esa directamente (no query).
 * - Si no hay conversación explícita (API manual), se busca la más
 *   reciente del contacto dentro del tenant.
 * - NO filtra por bot_paused ni status: U3 solo representa assignment,
 *   U4 decidirá si el trigger aplica.
 * - Determinista: ordena por updated_at DESC, created_at ASC, id ASC.
 * - Tenant-safe: filtra SIEMPRE por tenant_id.
 */
final class ContactConversationResolver
{
    /**
     * Resuelve la conversación para un tag assignment manual/API.
     */
    public function resolveForTagAssignment(Tenant $tenant, Contact $contact): ?Conversation
    {
        return Conversation::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('contact_id', $contact->id)
            ->orderByDesc('updated_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();
    }
}
