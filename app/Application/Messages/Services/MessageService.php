<?php

declare(strict_types=1);

namespace App\Application\Messages\Services;

use App\Application\Audit\Services\AuditLogger;
use App\Application\Contacts\Services\ContactService;
use App\Application\Conversations\Services\ConversationService;
use App\Application\Flows\Services\ConversationLockContext;
use App\Application\Flows\Services\FlowExecutionService;
use App\Application\Users\Services\AuthorizationService;
use App\Domain\Billing\Contracts\UsageGuardInterface;
use App\Domain\Billing\Enums\UsageCategory;
use App\Domain\Conversations\Enums\ConversationStatus;
use App\Domain\Conversations\Enums\InboxConversationChangeKind;
use App\Domain\Conversations\Exceptions\ConversationInvalidStateException;
use App\Domain\Conversations\Exceptions\ConversationNotFoundException;
use App\Domain\Conversations\Exceptions\ConversationReplyForbiddenException;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Messages\Enums\MessageDirection;
use App\Domain\Messages\Enums\MessageOrigin;
use App\Domain\Messages\Enums\MessageStatus;
use App\Domain\Messages\Enums\MessageType;
use App\Domain\Messages\Models\Message;
use App\Domain\Messages\ValueObjects\InboundMessageResult;
use App\Domain\Messages\ValueObjects\NormalizedInboundMessage;
use App\Domain\Messages\ValueObjects\NormalizedStatusUpdate;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\TenantMembershipStatus;
use App\Domain\Users\Enums\TenantPermission;
use App\Domain\Users\Models\TenantUser;
use App\Domain\Users\Models\User;
use App\Events\ConversationUpdated;
use App\Events\InboxConversationChanged;
use App\Events\MessageCreated;
use App\Events\MessageStatusUpdated;
use App\Infrastructure\Logging\SafeLogContext;
use App\Infrastructure\Tenancy\TenantContext;
use App\Jobs\SendWhatsAppMessage;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Persistencia de mensajes (FASE 9, ADR-032).
 *
 * - Inbound (webhook): contact → conversation → message, con idempotencia por
 *   `provider_message_id` (índice UNIQUE parcial + backstop QueryException).
 *   Tipos no soportados de Meta lanzan `UnsupportedMessageTypeException` (el
 *   job marca el evento `failed`, permanente; el webhook responde 200).
 * - Status (webhook): actualiza el mensaje por `provider_message_id`, nunca
 *   crea mensajes. `failed` además pasa la conversación a `pending`.
 * - Outbound: crea el mensaje `pending` y encola `SendWhatsAppMessage`.
 *
 * Todo filtrado por `tenant_id` (sin scope en la resolución, aislamiento real);
 * el `TenantContext` se establece solo alrededor de los creates y se libera en
 * `finally` (los jobs son tenant-aware, ADR-021).
 */
final class MessageService
{
    public const HANDOFF_BLOCK_CODE = 'BOT_PAUSED_HANDOFF';

    public const QUEUE_EXHAUSTED_CODE = 'MESSAGE_QUEUE_ATTEMPTS_EXHAUSTED';

    public const LOCK_TIMEOUT_CODE = 'MESSAGE_CONVERSATION_LOCK_TIMEOUT';

    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly ContactService $contacts,
        private readonly ConversationService $conversations,
        private readonly AuthorizationService $authorization,
        private readonly Dispatcher $events,
        private readonly FlowExecutionService $flowExecutions,
        private readonly ConversationLockContext $lockContext,
        private readonly MessageOriginClassifier $originClassifier,
        private readonly UsageGuardInterface $usageGuard,
    ) {}

    /**
     * Persiste un mensaje entrante de Meta. Devuelve `InboundMessageResult`:
     * `message` es null si el payload no es procesable (falta id/from); lanza
     * UnsupportedMessageTypeException si el tipo no se persiste; si el mensaje
     * ya existía (dedupe por `provider_message_id`) devuelve el existente con
     * `created = false`. El motor de flujos (FASE 11) se engancha después de
     * esta persistencia con su propia barrera de idempotencia.
     *
     * @param  array<string, mixed>|NormalizedInboundMessage  $eventData
     */
    public function handleInboundMessage(Tenant $tenant, array|NormalizedInboundMessage $eventData): InboundMessageResult
    {
        $normalized = is_array($eventData) ? NormalizedInboundMessage::fromProvider($eventData) : $eventData;

        if ($normalized === null) {
            Log::warning('messages.inbound_invalid_payload');

            return InboundMessageResult::unprocessable();
        }

        $existing = $this->findByProviderMessageId($tenant, $normalized->providerMessageId);

        if ($existing !== null) {
            return InboundMessageResult::existing($existing);
        }

        $contact = $this->contacts->findOrCreateForPhone($tenant, $normalized->sender);
        $conversation = $this->conversations->findOrCreateActiveForContact($tenant, $contact->id);

        try {
            $message = TenantContext::withId($tenant->id, fn (): Message => Message::query()->create([
                'conversation_id' => $conversation->id,
                'provider_message_id' => $normalized->providerMessageId,
                'direction' => MessageDirection::Inbound,
                'type' => $normalized->type,
                'status' => MessageStatus::Delivered,
                'body' => $normalized->body,
                'media_mime' => $normalized->mediaMime,
                'media_size' => $normalized->mediaSize,
                'metadata' => $normalized->metadata + [
                    'from' => $normalized->sender,
                    'provider_timestamp' => $normalized->providerTimestamp,
                ],
                'delivered_at' => $this->providerTimestamp($normalized->providerTimestamp),
            ]));
        } catch (QueryException $e) {
            $existing = $this->findByProviderMessageId($tenant, $normalized->providerMessageId);

            if ($existing !== null) {
                return InboundMessageResult::existing($existing);
            }

            throw $e;
        }

        $reopened = $this->touchConversation($tenant, $conversation->id, $normalized->providerTimestamp);

        $this->auditLogger->record(
            action: 'message.received',
            data: [
                'tenant_id' => $tenant->id,
                'conversation_id' => $conversation->id,
                'provider_message_id' => $normalized->providerMessageId,
                'type' => $normalized->type->value,
                'reopened' => $reopened,
            ],
            subjectType: Message::class,
            subjectId: $message->id,
            tenantId: $tenant->id,
        );

        $this->events->dispatch(new MessageCreated($message));

        return InboundMessageResult::created($message);
    }

    /**
     * Aplica status de Meta de forma monotónica. Un status inferior o repetido
     * no cambia la fila, no audita y no emite un broadcast.
     *
     * @param  array<string, mixed>|NormalizedStatusUpdate  $eventData
     */
    public function handleStatusUpdate(Tenant $tenant, array|NormalizedStatusUpdate $eventData): void
    {
        $normalized = is_array($eventData) ? NormalizedStatusUpdate::fromProvider($eventData) : $eventData;

        if ($normalized === null) {
            return;
        }

        $transition = DB::transaction(function () use ($tenant, $normalized): ?array {
            $message = Message::query()
                ->withoutTenantScope()
                ->where('tenant_id', $tenant->id)
                ->where('provider_message_id', $normalized->providerMessageId)
                ->lockForUpdate()
                ->first();

            if ($message === null) {
                Log::warning('messages.status_without_message', [
                    'provider_message_id' => $normalized->providerMessageId,
                ]);

                return null;
            }

            if (! $this->shouldAdvanceStatus($message->status, $normalized->status)) {
                return null;
            }

            $statusColumn = $normalized->status->columnFor();

            if ($statusColumn === null) {
                return null;
            }

            $fill = [
                'status' => $normalized->status,
                $statusColumn => $this->providerTimestamp($normalized->providerTimestamp),
            ];

            if ($normalized->status === MessageStatus::Failed && $normalized->failureDetails !== []) {
                $fill['metadata'] = array_merge($message->metadata ?? [], [
                    'status_failure' => $this->safeFailureDetails($normalized->failureDetails),
                ]);
            }

            $previous = $message->status->value;
            $message->forceFill($fill)->save();

            return [$message, $previous, $normalized->status];
        });

        if ($transition === null) {
            return;
        }

        /** @var Message $message */
        [$message, $previous, $status] = $transition;

        if ($status === MessageStatus::Failed) {
            $this->markConversationPending($tenant, $message->conversation_id);
        }

        $this->auditLogger->record(
            action: 'message.status_updated',
            data: [
                'tenant_id' => $tenant->id,
                'provider_message_id' => $normalized->providerMessageId,
                'status' => $status->value,
            ],
            subjectType: Message::class,
            subjectId: $message->id,
            tenantId: $tenant->id,
        );

        $this->events->dispatch(new MessageStatusUpdated($message, $previous));
    }

    /**
     * Crea un mensaje saliente (texto) en `pending` y encola su envío.
     *
     * Refresca los timestamps de la conversación (`last_message_at` /
     * `last_interaction_at`) para mantener el orden de la lista del inbox y
     * emite `MessageCreated` + `ConversationUpdated` (FASE 10, ADR-033).
     *
     * @param  array<string, mixed>  $metadata
     */
    public function createOutbound(
        Tenant $tenant,
        Conversation $conversation,
        string $body,
        MessageOrigin $origin = MessageOrigin::Automation,
        ?User $actor = null,
        array $metadata = [],
    ): Message {
        if ($conversation->tenant_id !== $tenant->id) {
            throw new \InvalidArgumentException('La conversación no pertenece al tenant del outbound.');
        }

        if (($origin === MessageOrigin::Human) !== ($actor !== null)) {
            throw new \InvalidArgumentException('Los mensajes humanos requieren actor y solo ellos pueden atribuirlo.');
        }

        if ($actor !== null && ! TenantUser::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $actor->id)
            ->where('status', TenantMembershipStatus::Active->value)
            ->exists()) {
            throw new \InvalidArgumentException('El actor del mensaje no es miembro activo del tenant.');
        }

        $messageId = (string) Str::uuid();
        $idempotencyKey = "message:{$messageId}";

        $reservation = TenantContext::withId($tenant->id, fn () => $this->usageGuard->reserve(
            tenant: $tenant,
            category: UsageCategory::Messages,
            quantity: 1,
            idempotencyKey: $idempotencyKey,
            ttlSeconds: 900,
        ));

        $message = TenantContext::withId($tenant->id, function () use ($conversation, $body, $origin, $actor, $metadata, $messageId): Message {
            $message = new Message([
                'conversation_id' => $conversation->id,
                'direction' => MessageDirection::Outbound,
                'type' => MessageType::Text,
                'status' => MessageStatus::Pending,
                'body' => $body,
                'metadata' => array_merge($metadata, [
                    'text' => $body,
                    'origin' => $origin->value,
                    'attempt_tracking' => 'message_id_v1',
                ]),
            ]);
            $message->forceFill([
                'id' => $messageId,
                'sent_by_user_id' => $actor?->id,
            ]);
            $message->save();

            return $message;
        });

        $this->bumpConversationTimestamps($tenant, $conversation->id);

        dispatch(
            (new SendWhatsAppMessage($tenant->id, $conversation->id, $message->id))
                ->forTenant($tenant->id),
        );

        $this->events->dispatch(new MessageCreated($message));

        return $message;
    }

    /**
     * Cancela de forma persistente automation creada antes del handoff. Debe
     * ejecutarse dentro de la transacción y conversationLock del handoff.
     */
    public function blockAutomationForHandoff(Tenant $tenant, Conversation $conversation): int
    {
        $messages = Message::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('conversation_id', $conversation->id)
            ->where('direction', MessageDirection::Outbound->value)
            ->whereIn('status', [MessageStatus::Pending->value, MessageStatus::Sending->value])
            ->get();

        $blocked = 0;

        foreach ($messages as $message) {
            if (! $this->originClassifier->isAutomation($message)) {
                continue;
            }

            $this->blockAutomaticMessageForHandoff($tenant, $message);
            $blocked++;
        }

        return $blocked;
    }

    public function blockAutomaticMessageForHandoff(Tenant $tenant, Message $message): void
    {
        if ($message->tenant_id !== $tenant->id
            || ! in_array($message->status, [MessageStatus::Pending, MessageStatus::Sending], true)) {
            return;
        }

        $previous = $message->status->value;

        $message->forceFill([
            'status' => MessageStatus::Failed,
            'failed_at' => now(),
            'metadata' => array_merge($message->metadata ?? [], [
                'error_code' => self::HANDOFF_BLOCK_CODE,
                'error_source' => 'internal',
            ]),
        ])->save();

        $this->auditLogger->record(
            action: 'message.failed',
            data: [
                'tenant_id' => $tenant->id,
                'conversation_id' => $message->conversation_id,
                'error_code' => self::HANDOFF_BLOCK_CODE,
                'error_message' => null,
            ],
            subjectType: Message::class,
            subjectId: $message->id,
            tenantId: $tenant->id,
        );

        $this->events->dispatch(new MessageStatusUpdated($message, $previous));
    }

    /**
     * Historial de mensajes de una conversación (paginado DESC) para el inbox.
     *
     * Autoriza `conversations.view`; la conversación se resuelve filtrando por
     * `tenant_id` autorizado (404 oculta la existencia cross-tenant).
     *
     * @param  array{per_page?: int}  $filters
     * @return LengthAwarePaginator<int, Message>
     */
    public function indexForUser(User $user, Tenant $tenant, string $conversationId, array $filters): LengthAwarePaginator
    {
        $this->authorization->authorize($user, TenantPermission::ViewConversations, $tenant);

        $conversation = $this->findConversationForTenant($tenant, $conversationId);

        return Message::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('created_at')
            ->paginate($filters['per_page'] ?? 30);
    }

    /**
     * Envía un mensaje de texto desde el inbox (FASE 10, ADR-033).
     *
     * Autoriza `messages.send` (owner/admin/agent). La conversación se resuelve
     * filtrando por `tenant_id` autorizado; `tenant_id` nunca viene del frontend.
     */
    public function send(User $user, Tenant $tenant, string $conversationId, string $body): Message
    {
        $this->authorization->authorize($user, TenantPermission::SendMessages, $tenant);

        $lock = $this->flowExecutions->conversationLock($tenant, $conversationId);
        $acquired = false;

        try {
            $lock->block(seconds: 10);
            $acquired = true;
            $this->lockContext->enter($tenant->id, $conversationId, $lock);

            return DB::transaction(function () use ($user, $tenant, $conversationId, $body): Message {
                $conversation = $this->findConversationForTenantForUpdate($tenant, $conversationId);

                TenantUser::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->first();

                $this->authorization->authorize($user, TenantPermission::SendMessages, $tenant);

                if (! in_array($conversation->status, [ConversationStatus::Open, ConversationStatus::Pending], true)) {
                    throw new ConversationInvalidStateException(
                        'Solo se puede responder una conversación abierta o pendiente.',
                    );
                }

                if (! $this->authorization->can($user, TenantPermission::ManageConversations, $tenant)
                    && (int) $conversation->agent_id !== $user->id) {
                    throw ConversationReplyForbiddenException::notAssignedToActor();
                }

                return $this->createOutbound(
                    $tenant,
                    $conversation,
                    $body,
                    MessageOrigin::Human,
                    $user,
                );
            });
        } catch (LockTimeoutException) {
            throw ConversationReplyForbiddenException::busy();
        } finally {
            if ($acquired) {
                $this->lockContext->leave($tenant->id, $conversationId);
                $lock->release();
            }
        }
    }

    private function findConversationForTenantForUpdate(Tenant $tenant, string $conversationId): Conversation
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

    private function findConversationForTenant(Tenant $tenant, string $conversationId): Conversation
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

    /**
     * Actualiza `last_message_at`/`last_interaction_at` al enviar un mensaje
     * saliente (no reabre estados resueltos/archivados).
     */
    private function bumpConversationTimestamps(Tenant $tenant, string $conversationId): void
    {
        $conversation = Conversation::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->whereKey($conversationId)
            ->first();

        if ($conversation === null) {
            return;
        }

        $conversation->forceFill([
            'last_message_at' => now(),
            'last_interaction_at' => now(),
        ])->save();

        $conversation->loadMissing(['contact', 'agent']);

        $this->events->dispatch(new ConversationUpdated($conversation));
    }

    private function findByProviderMessageId(Tenant $tenant, string $providerMessageId): ?Message
    {
        return Message::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('provider_message_id', $providerMessageId)
            ->first();
    }

    /**
     * Actualiza `last_message_at`/`last_interaction_at` de la conversación y la
     * reabre si estaba resuelta/archivada. Devuelve si se reabrió. Emite
     * `ConversationUpdated` (FASE 10) para refrescar el inbox en tiempo real.
     */
    private function touchConversation(Tenant $tenant, string $conversationId, ?string $providerTimestamp): bool
    {
        $conversation = Conversation::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->whereKey($conversationId)
            ->first();

        if ($conversation === null) {
            return false;
        }

        $reopened = in_array($conversation->status, [ConversationStatus::Resolved, ConversationStatus::Archived], true);

        $conversation->forceFill([
            'last_message_at' => $this->providerTimestamp($providerTimestamp),
            'last_interaction_at' => now(),
            ...($reopened ? ['status' => ConversationStatus::Open] : []),
        ])->save();

        $conversation->loadMissing(['contact', 'agent']);

        $this->events->dispatch(new ConversationUpdated($conversation));

        if ($reopened) {
            $this->events->dispatch(new InboxConversationChanged(
                $conversation,
                InboxConversationChangeKind::ConversationUpdated,
            ));
        }

        return $reopened;
    }

    private function markConversationPending(Tenant $tenant, string $conversationId): void
    {
        $conversation = Conversation::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->whereKey($conversationId)
            ->first();

        if ($conversation === null || $conversation->status === ConversationStatus::Pending) {
            return;
        }

        $conversation->forceFill(['status' => ConversationStatus::Pending])->save();

        $conversation->loadMissing(['contact', 'agent']);

        $this->events->dispatch(new ConversationUpdated($conversation));
    }

    private function providerTimestamp(?string $providerTimestamp): Carbon
    {
        if ($providerTimestamp === null || $providerTimestamp === '' || ! is_numeric($providerTimestamp)) {
            return now();
        }

        return Carbon::createFromTimestampUTC((int) $providerTimestamp);
    }

    private function shouldAdvanceStatus(MessageStatus $current, MessageStatus $incoming): bool
    {
        if ($current === MessageStatus::Failed) {
            return false;
        }

        if ($incoming === MessageStatus::Failed) {
            return in_array($current, [MessageStatus::Pending, MessageStatus::Sending, MessageStatus::Sent], true);
        }

        $rank = static fn (MessageStatus $status): int => match ($status) {
            MessageStatus::Pending, MessageStatus::Sending => 0,
            MessageStatus::Sent => 1,
            MessageStatus::Delivered => 2,
            MessageStatus::Read => 3,
            MessageStatus::Failed => -1,
        };

        return $rank($incoming) > $rank($current);
    }

    /**
     * @param  array<string, string>  $details
     * @return array<string, string>
     */
    private function safeFailureDetails(array $details): array
    {
        $safe = [];

        foreach ($details as $key => $value) {
            $sanitized = SafeLogContext::sanitizeProviderMessage($value);
            $safe[$key] = preg_replace('/(?<!\d)\d{7,}(?!\d)/', '[REDACTED]', $sanitized) ?? '[REDACTED]';
        }

        return $safe;
    }
}
