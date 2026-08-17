<?php

declare(strict_types=1);

namespace App\Application\Conversations\Services;

use App\Application\Audit\Services\AuditLogger;
use App\Application\Flows\Services\ConversationLockContext;
use App\Application\Flows\Services\FlowExecutionService;
use App\Application\Users\Services\AuthorizationService;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Conversations\Enums\ConversationStatus;
use App\Domain\Conversations\Enums\InboxConversationChangeKind;
use App\Domain\Conversations\Exceptions\ConversationAgentNotInTenantException;
use App\Domain\Conversations\Exceptions\ConversationAssignmentConflictException;
use App\Domain\Conversations\Exceptions\ConversationContactNotFoundException;
use App\Domain\Conversations\Exceptions\ConversationInvalidStateException;
use App\Domain\Conversations\Exceptions\ConversationNotFoundException;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Conversations\Models\ConversationAssignment;
use App\Domain\Conversations\Models\ConversationParticipant;
use App\Domain\Tenants\Exceptions\TenantMembershipException;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\TenantMembershipStatus;
use App\Domain\Users\Enums\TenantPermission;
use App\Domain\Users\Models\TenantUser;
use App\Domain\Users\Models\User;
use App\Events\ConversationUpdated;
use App\Events\InboxConversationChanged;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
    private const OPEN_ASSIGNMENT_UNIQUE = 'conversation_assignments_open_unique';

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly AuditLogger $auditLogger,
        private readonly Dispatcher $events,
        private readonly FlowExecutionService $flowExecutions,
        private readonly ConversationLockContext $lockContext,
    ) {}

    /**
     * @param  array{search?: string, status?: string, agent_id?: int|string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, Conversation>
     */
    public function index(User $user, Tenant $tenant, array $filters): LengthAwarePaginator
    {
        $this->authorization->authorize($user, TenantPermission::ViewConversations, $tenant);

        $query = Conversation::query()->with(['contact', 'agent', 'lastMessage']);

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

        $conversation->loadMissing(['contact', 'agent']);

        $this->events->dispatch(new ConversationUpdated($conversation));

        return $conversation;
    }

    /**
     * Asigna la conversación a un agente del tenant (asignación manual).
     */
    public function assign(User $user, Tenant $tenant, string $conversationId, int $agentId): Conversation
    {
        return $this->changeAgent($user, $tenant, $conversationId, $agentId, operation: 'assign');
    }

    /**
     * Transfiere la conversación a otro agente del tenant.
     */
    public function transfer(User $user, Tenant $tenant, string $conversationId, int $agentId): Conversation
    {
        return $this->changeAgent($user, $tenant, $conversationId, $agentId, operation: 'transfer');
    }

    /**
     * Reclama para el usuario autenticado una conversación en cola de handoff.
     */
    public function claim(User $user, Tenant $tenant, string $conversationId): Conversation
    {
        return $this->changeAgent($user, $tenant, $conversationId, $user->id, operation: 'claim');
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

        $conversation->loadMissing(['contact', 'agent']);

        $this->events->dispatch(new ConversationUpdated($conversation));
        $this->events->dispatch(new InboxConversationChanged(
            $conversation,
            InboxConversationChangeKind::ConversationUpdated,
        ));

        return $conversation;
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

        $conversation->loadMissing(['contact', 'agent']);

        $this->events->dispatch(new ConversationUpdated($conversation));
        $this->events->dispatch(new InboxConversationChanged(
            $conversation,
            InboxConversationChangeKind::ConversationUpdated,
        ));

        return $conversation;
    }

    public function pauseBot(User $user, Tenant $tenant, string $conversationId): Conversation
    {
        return $this->changeBotState($user, $tenant, $conversationId, paused: true);
    }

    public function resumeBot(User $user, Tenant $tenant, string $conversationId): Conversation
    {
        return $this->changeBotState($user, $tenant, $conversationId, paused: false);
    }

    private function changeBotState(
        User $user,
        Tenant $tenant,
        string $conversationId,
        bool $paused,
    ): Conversation {
        $this->authorization->authorize($user, TenantPermission::ManageConversations, $tenant);

        $lock = $this->flowExecutions->conversationLock($tenant, $conversationId);

        try {
            $lock->block(seconds: 10);
        } catch (LockTimeoutException) {
            throw new ConversationInvalidStateException(
                'La conversación está siendo modificada; intente nuevamente.',
            );
        }

        $this->lockContext->enter($tenant->id, $conversationId, $lock);

        try {
            $changed = DB::transaction(function () use ($user, $tenant, $conversationId, $paused): bool {
                $conversation = $this->findForTenantForUpdate($tenant, $conversationId);
                $this->lockedMemberships($tenant, $user->id, $user->id);
                $this->authorization->authorize($user, TenantPermission::ManageConversations, $tenant);

                if (! $paused) {
                    $this->flowExecutions->finalizeCommittedHandoff($conversation);
                }

                if ($conversation->bot_paused === $paused) {
                    return false;
                }

                $changes = ['bot_paused' => $paused];

                // Una pausa manual inicia un estado distinto de un handoff
                // anterior; resume conserva el timestamp histórico (ADR-051).
                if ($paused) {
                    $changes['handoff_requested_at'] = null;
                }

                $conversation->forceFill($changes)->save();

                $this->auditLogger->record(
                    action: $paused ? 'conversation.bot_paused' : 'conversation.bot_resumed',
                    data: ['tenant_id' => $tenant->id],
                    subjectType: Conversation::class,
                    subjectId: $conversation->id,
                    actorUserId: $user->id,
                    tenantId: $tenant->id,
                );

                return true;
            });
        } finally {
            $this->lockContext->leave($tenant->id, $conversationId);
            $lock->release();
        }

        $conversation = $this->findForTenant($tenant, $conversationId);
        $conversation->loadMissing(['contact', 'agent']);

        if ($changed) {
            $this->events->dispatch(new ConversationUpdated($conversation));

            if (! $paused) {
                $this->events->dispatch(new InboxConversationChanged(
                    $conversation,
                    InboxConversationChangeKind::BotResumed,
                ));
            }
        }

        return $conversation;
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

        return TenantContext::withId($tenant->id, fn (): Conversation => Conversation::query()->create([
            'contact_id' => $contactId,
            'status' => ConversationStatus::Open,
        ]));
    }

    /**
     * @param  'assign'|'transfer'|'claim'  $operation
     */
    private function changeAgent(
        User $user,
        Tenant $tenant,
        string $conversationId,
        int $agentId,
        string $operation,
    ): Conversation {
        $permission = $operation === 'claim'
            ? TenantPermission::ClaimConversations
            : TenantPermission::AssignConversations;

        $this->authorization->authorize($user, $permission, $tenant);

        $lock = $this->flowExecutions->conversationLock($tenant, $conversationId);

        try {
            $lock->block(seconds: 10);
        } catch (LockTimeoutException) {
            throw ConversationAssignmentConflictException::busy();
        }

        $this->lockContext->enter($tenant->id, $conversationId, $lock);

        try {
            return $this->changeAgentLocked(
                $user,
                $tenant,
                $conversationId,
                $agentId,
                $operation,
                $permission,
            );
        } finally {
            $this->lockContext->leave($tenant->id, $conversationId);
            $lock->release();
        }
    }

    /**
     * @param  'assign'|'transfer'|'claim'  $operation
     */
    private function changeAgentLocked(
        User $user,
        Tenant $tenant,
        string $conversationId,
        int $agentId,
        string $operation,
        TenantPermission $permission,
    ): Conversation {
        try {
            $changed = DB::transaction(function () use (
                $user,
                $tenant,
                $conversationId,
                $agentId,
                $operation,
                $permission,
            ): bool {
                $conversation = $this->findForTenantForUpdate($tenant, $conversationId);
                $memberships = $this->lockedMemberships($tenant, $user->id, $agentId);

                // La autorización se repite después de bloquear la membresía del actor.
                $this->authorization->authorize($user, $permission, $tenant);

                $targetMembership = $memberships[$agentId] ?? null;
                if ($targetMembership === null || $targetMembership->status !== TenantMembershipStatus::Active) {
                    throw new ConversationAgentNotInTenantException;
                }

                $this->assertAssignableStatus($conversation);

                $openAssignment = $this->openAssignmentForUpdate($tenant, $conversation);
                $previousAgentId = $conversation->agent_id === null ? null : (int) $conversation->agent_id;
                $now = now();

                if ($operation === 'claim') {
                    $this->assertClaimable($conversation, $openAssignment);
                } elseif ($operation === 'assign') {
                    if ($previousAgentId === $agentId) {
                        if ($openAssignment === null
                            || (int) $openAssignment->agent_id !== $agentId
                            || $this->activeParticipantForUpdate($tenant, $conversation, $agentId) === null) {
                            throw ConversationAssignmentConflictException::inconsistent();
                        }

                        return false;
                    }

                    if ($previousAgentId !== null) {
                        throw ConversationAssignmentConflictException::alreadyAssigned();
                    }

                    if ($openAssignment !== null) {
                        throw ConversationAssignmentConflictException::inconsistent();
                    }
                } else {
                    if ($previousAgentId === null) {
                        throw ConversationAssignmentConflictException::notAssigned();
                    }

                    if ($previousAgentId === $agentId) {
                        throw ConversationAssignmentConflictException::sameTransferAgent();
                    }

                    if ($openAssignment === null || (int) $openAssignment->agent_id !== $previousAgentId) {
                        throw ConversationAssignmentConflictException::inconsistent();
                    }

                    $previousParticipant = $this->activeParticipantForUpdate(
                        $tenant,
                        $conversation,
                        $previousAgentId,
                    );

                    if ($previousParticipant === null) {
                        throw ConversationAssignmentConflictException::inconsistent();
                    }

                    $openAssignment->forceFill(['unassigned_at' => $now])->save();
                    $previousParticipant->forceFill(['left_at' => $now])->save();
                }

                $reason = match ($operation) {
                    'assign' => 'manual',
                    'transfer' => 'transfer',
                    'claim' => 'claim',
                };

                $conversation->assignments()->create([
                    'agent_id' => $agentId,
                    'assigned_by' => $user->id,
                    'assigned_at' => $now,
                    'reason' => $reason,
                ]);

                $this->activateParticipant($tenant, $conversation, $targetMembership, $now);

                $conversation->forceFill([
                    'agent_id' => $agentId,
                    'auto_assigned' => false,
                ])->save();

                $this->auditLogger->record(
                    action: match ($operation) {
                        'assign' => 'conversation.assigned',
                        'transfer' => 'conversation.transferred',
                        'claim' => 'conversation.claimed',
                    },
                    data: [
                        'conversation_id' => $conversation->id,
                        'previous_agent_id' => $previousAgentId,
                        'agent_id' => $agentId,
                        'reason' => $reason,
                    ],
                    subjectType: Conversation::class,
                    subjectId: $conversation->id,
                    actorUserId: $user->id,
                    tenantId: $tenant->id,
                );

                return true;
            });
        } catch (QueryException $e) {
            if ($this->isOpenAssignmentUniqueViolation($e)) {
                throw ConversationAssignmentConflictException::alreadyAssigned();
            }

            throw $e;
        }

        $conversation = $this->findForTenant($tenant, $conversationId);
        $conversation->loadMissing(['contact', 'agent']);

        if ($changed) {
            $this->events->dispatch(new ConversationUpdated($conversation));

            $kind = match ($operation) {
                'assign' => InboxConversationChangeKind::Assigned,
                'transfer' => InboxConversationChangeKind::Transferred,
                'claim' => InboxConversationChangeKind::Claimed,
            };
            $this->events->dispatch(new InboxConversationChanged($conversation, $kind));
        }

        return $conversation;
    }

    /**
     * Bloquea memberships en orden determinista para evitar deadlocks entre
     * operaciones sobre conversaciones distintas.
     *
     * @return array<int, TenantUser>
     */
    private function lockedMemberships(Tenant $tenant, int $actorId, int $agentId): array
    {
        $userIds = array_values(array_unique([$actorId, $agentId]));
        sort($userIds);

        /** @var array<int, TenantUser> $memberships */
        $memberships = TenantUser::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('user_id', $userIds)
            ->orderBy('user_id')
            ->lockForUpdate()
            ->get()
            ->keyBy(static fn (TenantUser $membership): int => (int) $membership->user_id)
            ->all();

        if (! isset($memberships[$actorId])
            || $memberships[$actorId]->status !== TenantMembershipStatus::Active) {
            throw new TenantMembershipException('El usuario no es miembro activo del tenant.');
        }

        return $memberships;
    }

    private function assertAssignableStatus(Conversation $conversation): void
    {
        if (! in_array($conversation->status, [ConversationStatus::Open, ConversationStatus::Pending], true)) {
            throw new ConversationInvalidStateException(
                'Solo se puede asignar una conversación abierta o pendiente.',
            );
        }
    }

    private function assertClaimable(
        Conversation $conversation,
        ?ConversationAssignment $openAssignment,
    ): void {
        if ($conversation->agent_id !== null) {
            throw ConversationAssignmentConflictException::alreadyAssigned();
        }

        if (! $conversation->bot_paused || $conversation->handoff_requested_at === null) {
            throw ConversationAssignmentConflictException::notAwaitingHandoff();
        }

        if ($openAssignment !== null) {
            throw ConversationAssignmentConflictException::inconsistent();
        }
    }

    private function openAssignmentForUpdate(
        Tenant $tenant,
        Conversation $conversation,
    ): ?ConversationAssignment {
        return ConversationAssignment::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('conversation_id', $conversation->id)
            ->whereNull('unassigned_at')
            ->lockForUpdate()
            ->first();
    }

    private function activeParticipantForUpdate(
        Tenant $tenant,
        Conversation $conversation,
        int $userId,
    ): ?ConversationParticipant {
        return ConversationParticipant::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $userId)
            ->whereNull('left_at')
            ->lockForUpdate()
            ->first();
    }

    private function activateParticipant(
        Tenant $tenant,
        Conversation $conversation,
        TenantUser $membership,
        Carbon $now,
    ): void {
        $participant = ConversationParticipant::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $membership->user_id)
            ->lockForUpdate()
            ->first();

        if ($participant === null) {
            $conversation->participants()->create([
                'user_id' => $membership->user_id,
                'role' => $membership->role->value,
                'joined_at' => $now,
            ]);

            return;
        }

        $participant->forceFill([
            'role' => $membership->role->value,
            'left_at' => null,
        ])->save();
    }

    private function findForTenantForUpdate(Tenant $tenant, string $conversationId): Conversation
    {
        $conversation = Conversation::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->whereKey($conversationId)
            ->lockForUpdate()
            ->first();

        if ($conversation === null) {
            throw new ConversationNotFoundException;
        }

        return $conversation;
    }

    private function isOpenAssignmentUniqueViolation(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, self::OPEN_ASSIGNMENT_UNIQUE)
            || str_contains($message, 'conversation_assignments.conversation_id');
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
