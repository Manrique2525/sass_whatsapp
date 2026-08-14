<?php

declare(strict_types=1);

namespace App\Application\Conversations\Services;

use App\Application\Audit\Services\AuditLogger;
use App\Application\Users\Services\AuthorizationService;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Conversations\Enums\ConversationStatus;
use App\Domain\Conversations\Exceptions\ConversationAgentNotInTenantException;
use App\Domain\Conversations\Exceptions\ConversationContactNotFoundException;
use App\Domain\Conversations\Exceptions\ConversationInvalidStateException;
use App\Domain\Conversations\Exceptions\ConversationNotFoundException;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Conversations\Models\ConversationAssignment;
use App\Domain\Conversations\Models\ConversationParticipant;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\TenantMembershipStatus;
use App\Domain\Users\Enums\TenantPermission;
use App\Domain\Users\Models\TenantUser;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Casos de uso del inbox de conversaciones (FASE 8, ADR-031).
 *
 * Invariantes:
 * - La conversación y su contacto se resuelven SIN el scope global
 *   (`withoutTenantScope`) pero SIEMPRE filtrando por `tenant_id` del tenant
 *   autorizado: el 404 oculta la existencia cross-tenant (ADR-010/023).
 * - `tenant_id` nunca viene del frontend: lo fija `BelongsToTenant` con el
 *   TenantContext activo.
 * - Los cambios de estado pasan por la máquina de estados
 *   (`ConversationStatus::canTransitionTo`); mismas transiciones = no-op.
 * - Asignar/transferir exige que el agente sea miembro ACTIVO del tenant
 *   (`tenant_users.status = active`); jamás se asigna un usuario de otro
 *   tenant.
 * - Toda mutación queda auditada (AuditLogger).
 */
final class ConversationService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array{search?: string, status?: string, agent_id?: int|string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, Conversation>
     */
    public function index(User $user, Tenant $tenant, array $filters): LengthAwarePaginator
    {
        $this->authorization->authorize($user, TenantPermission::ViewConversations, $tenant);

        $query = Conversation::query()->with(['contact', 'agent']);

        if (isset($filters['status']) && $filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['agent_id']) && $filters['agent_id'] !== '') {
            $query->where('agent_id', $filters['agent_id']);
        }

        if (isset($filters['search']) && $filters['search'] !== '') {
            $term = '%'.$filters['search'].'%';
            $query->whereHas('contact', function ($q) use ($term): void {
                $q->where('name', 'like', $term)
                    ->orWhere('phone', 'like', $term)
                    ->orWhere('email', 'like', $term);
            });
        }

        // Últimas interacciones primero; sin interacción al final.
        $query->orderByRaw('CASE WHEN last_interaction_at IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('last_interaction_at')
            ->orderByDesc('created_at');

        return $query->paginate($filters['per_page'] ?? 15);
    }

    public function showForUser(User $user, Tenant $tenant, string $conversationId): Conversation
    {
        $this->authorization->authorize($user, TenantPermission::ViewConversations, $tenant);

        $conversation = $this->findForTenant($tenant, $conversationId);
        $conversation->loadMissing(['contact', 'agent', 'participants.user', 'assignments.agent']);

        return $conversation;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function create(User $user, Tenant $tenant, array $validated): Conversation
    {
        $this->authorization->authorize($user, TenantPermission::ManageConversations, $tenant);

        $contact = $this->findContactForTenant($tenant, (string) $validated['contact_id']);

        $conversation = Conversation::query()->create([
            'contact_id' => $contact->id,
            'status' => isset($validated['status'])
                ? ConversationStatus::from((string) $validated['status'])
                : ConversationStatus::Open,
            'bot_paused' => (bool) ($validated['bot_paused'] ?? false),
            'context' => $validated['context'] ?? null,
        ]);

        $this->auditLogger->record(
            action: 'conversation.created',
            data: ['tenant_id' => $tenant->id],
            subjectType: Conversation::class,
            subjectId: $conversation->id,
        );

        return $conversation->loadMissing('contact');
    }

    /**
     * Actualización parcial: `status` (con máquina de estados) y `context`
     * (merge profundo por claves). El mismo estado no produce cambios (200).
     *
     * @param  array<string, mixed>  $validated
     */
    public function update(User $user, Tenant $tenant, string $conversationId, array $validated): Conversation
    {
        $this->authorization->authorize($user, TenantPermission::ManageConversations, $tenant);

        $conversation = $this->findForTenant($tenant, $conversationId);

        $changed = [];

        if (isset($validated['status'])) {
            $target = ConversationStatus::from((string) $validated['status']);

            if (! $conversation->status->canTransitionTo($target)) {
                throw new ConversationInvalidStateException(sprintf(
                    'No se puede pasar la conversación de "%s" a "%s".',
                    $conversation->status->value,
                    $target->value,
                ));
            }

            if ($conversation->status !== $target) {
                $conversation->status = $target;
                $changed[] = 'status';
            }
        }

        if (array_key_exists('context', $validated)) {
            if ($validated['context'] === null) {
                $conversation->context = null;
            } else {
                $conversation->context = array_replace(
                    $conversation->context ?? [],
                    (array) $validated['context'],
                );
            }
            $changed[] = 'context';
        }

        if ($changed === []) {
            return $conversation;
        }

        $conversation->save();

        $this->auditLogger->record(
            action: 'conversation.updated',
            data: [
                'tenant_id' => $tenant->id,
                'changed' => $changed,
            ],
            subjectType: Conversation::class,
            subjectId: $conversation->id,
        );

        return $conversation->fresh()->loadMissing(['contact', 'agent']);
    }

    /**
     * Asigna la conversación a un agente del tenant (asignación manual).
     */
    public function assign(User $user, Tenant $tenant, string $conversationId, int $agentId): Conversation
    {
        return $this->changeAgent($user, $tenant, $conversationId, $agentId, transfer: false);
    }

    /**
     * Transfiere la conversación a otro agente del tenant.
     */
    public function transfer(User $user, Tenant $tenant, string $conversationId, int $agentId): Conversation
    {
        return $this->changeAgent($user, $tenant, $conversationId, $agentId, transfer: true);
    }

    public function close(User $user, Tenant $tenant, string $conversationId): Conversation
    {
        $this->authorization->authorize($user, TenantPermission::ManageConversations, $tenant);

        $conversation = $this->findForTenant($tenant, $conversationId);

        if ($conversation->status === ConversationStatus::Archived) {
            throw new ConversationInvalidStateException('No se puede cerrar una conversación archivada.');
        }

        if ($conversation->status === ConversationStatus::Resolved) {
            return $conversation;
        }

        $conversation->forceFill(['status' => ConversationStatus::Resolved])->save();

        $this->auditLogger->record(
            action: 'conversation.closed',
            data: ['tenant_id' => $tenant->id],
            subjectType: Conversation::class,
            subjectId: $conversation->id,
        );

        return $conversation->fresh()->loadMissing(['contact', 'agent']);
    }

    public function reopen(User $user, Tenant $tenant, string $conversationId): Conversation
    {
        $this->authorization->authorize($user, TenantPermission::ManageConversations, $tenant);

        $conversation = $this->findForTenant($tenant, $conversationId);

        if ($conversation->status === ConversationStatus::Open) {
            return $conversation;
        }

        $conversation->forceFill(['status' => ConversationStatus::Open])->save();

        $this->auditLogger->record(
            action: 'conversation.reopened',
            data: ['tenant_id' => $tenant->id],
            subjectType: Conversation::class,
            subjectId: $conversation->id,
        );

        return $conversation->fresh()->loadMissing(['contact', 'agent']);
    }

    public function pauseBot(User $user, Tenant $tenant, string $conversationId): Conversation
    {
        $this->authorization->authorize($user, TenantPermission::ManageConversations, $tenant);

        $conversation = $this->findForTenant($tenant, $conversationId);

        if ($conversation->bot_paused) {
            return $conversation;
        }

        $conversation->forceFill(['bot_paused' => true])->save();

        $this->auditLogger->record(
            action: 'conversation.bot_paused',
            data: ['tenant_id' => $tenant->id],
            subjectType: Conversation::class,
            subjectId: $conversation->id,
        );

        return $conversation->fresh()->loadMissing(['contact', 'agent']);
    }

    public function resumeBot(User $user, Tenant $tenant, string $conversationId): Conversation
    {
        $this->authorization->authorize($user, TenantPermission::ManageConversations, $tenant);

        $conversation = $this->findForTenant($tenant, $conversationId);

        if (! $conversation->bot_paused) {
            return $conversation;
        }

        $conversation->forceFill(['bot_paused' => false])->save();

        $this->auditLogger->record(
            action: 'conversation.bot_resumed',
            data: ['tenant_id' => $tenant->id],
            subjectType: Conversation::class,
            subjectId: $conversation->id,
        );

        return $conversation->fresh()->loadMissing(['contact', 'agent']);
    }

    /**
     * Reutiliza la conversación activa de un contacto o la crea (FASE 9).
     *
     * SIN autorización de usuario: lo invocan jobs del webhook de WhatsApp.
     * El webhook encontrará por defecto la conversación más reciente del
     * contacto; el motor de flujos decidirá si crear una nueva por nodo en
     * fases posteriores.
     */
    public function findOrCreateActiveForContact(Tenant $tenant, string $contactId): Conversation
    {
        $conversation = Conversation::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('contact_id', $contactId)
            ->orderByDesc('created_at')
            ->first();

        if ($conversation !== null) {
            return $conversation;
        }

        TenantContext::setId($tenant->id);

        try {
            $conversation = Conversation::query()->create([
                'contact_id' => $contactId,
                'status' => ConversationStatus::Open,
            ]);
        } finally {
            TenantContext::clear();
        }

        return $conversation;
    }

    /**
     * Lógica compartida de asignación manual y transferencia.
     */
    private function changeAgent(User $user, Tenant $tenant, string $conversationId, int $agentId, bool $transfer): Conversation
    {
        $this->authorization->authorize($user, TenantPermission::AssignConversations, $tenant);

        $conversation = $this->findForTenant($tenant, $conversationId);

        if ($conversation->agent_id === $agentId) {
            return $conversation;
        }

        $membership = $this->activeMembership($tenant, $agentId);

        $now = now();

        ConversationAssignment::query()
            ->where('conversation_id', $conversation->id)
            ->whereNull('unassigned_at')
            ->update(['unassigned_at' => $now]);

        ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->whereNull('left_at')
            ->update(['left_at' => $now]);

        $conversation->assignments()->create([
            'agent_id' => $agentId,
            'assigned_by' => $user->id,
            'assigned_at' => $now,
            'reason' => $transfer ? 'transfer' : 'manual',
        ]);

        ConversationParticipant::query()->updateOrCreate(
            ['conversation_id' => $conversation->id, 'user_id' => $agentId],
            ['role' => $membership->role->value, 'joined_at' => $now, 'left_at' => null],
        );

        $conversation->forceFill(['agent_id' => $agentId, 'auto_assigned' => false])->save();

        $this->auditLogger->record(
            action: $transfer ? 'conversation.transferred' : 'conversation.assigned',
            data: [
                'tenant_id' => $tenant->id,
                'agent_id' => $agentId,
                'assigned_by' => $user->id,
            ],
            subjectType: Conversation::class,
            subjectId: $conversation->id,
        );

        return $conversation->fresh()->loadMissing(['contact', 'agent']);
    }

    private function activeMembership(Tenant $tenant, int $userId): TenantUser
    {
        $membership = TenantUser::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $userId)
            ->where('status', TenantMembershipStatus::Active)
            ->first();

        if ($membership === null) {
            throw new ConversationAgentNotInTenantException;
        }

        return $membership;
    }

    private function findContactForTenant(Tenant $tenant, string $contactId): Contact
    {
        $contact = Contact::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->whereKey($contactId)
            ->first();

        if ($contact === null) {
            throw new ConversationContactNotFoundException;
        }

        return $contact;
    }

    private function findForTenant(Tenant $tenant, string $conversationId): Conversation
    {
        $conversation = Conversation::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->whereKey($conversationId)
            ->first();

        if ($conversation === null) {
            throw new ConversationNotFoundException;
        }

        return $conversation;
    }
}
