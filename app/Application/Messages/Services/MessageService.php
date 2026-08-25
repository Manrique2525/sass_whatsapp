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
use App\Domain\Messages\Exceptions\UnsupportedMessageTypeException;
use App\Domain\Messages\Models\Message;
use App\Domain\Messages\ValueObjects\InboundMessageResult;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\TenantMembershipStatus;
use App\Domain\Users\Enums\TenantPermission;
use App\Domain\Users\Models\TenantUser;
use App\Domain\Users\Models\User;
use App\Events\ConversationUpdated;
use App\Events\InboxConversationChanged;
use App\Events\MessageCreated;
use App\Events\MessageStatusUpdated;
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
     * @param  array<string, mixed>  $eventData
     */
    public function handleInboundMessage(Tenant $tenant, array $eventData): InboundMessageResult
    {
        $providerMessageId = isset($eventData['id']) && is_scalar($eventData['id']) ? (string) $eventData['id'] : '';
        $from = isset($eventData['from']) && is_scalar($eventData['from']) ? (string) $eventData['from'] : '';
        $providerType = isset($eventData['type']) && is_scalar($eventData['type']) ? (string) $eventData['type'] : '';
        $providerTimestamp = isset($eventData['timestamp']) && is_scalar($eventData['timestamp'])
            ? (string) $eventData['timestamp']
            : null;

        if ($providerMessageId === '' || $from === '') {
            Log::warning('messages.inbound_missing_fields', ['provider_message_id' => $providerMessageId]);

            return InboundMessageResult::unprocessable();
        }

        $existing = $this->findByProviderMessageId($tenant, $providerMessageId);

        if ($existing !== null) {
            return InboundMessageResult::existing($existing);
        }

        $type = MessageType::fromProvider($providerType);

        if ($type === null) {
            throw new UnsupportedMessageTypeException($providerType);
        }

        $contact = $this->contacts->findOrCreateForPhone($tenant, $from);
        $conversation = $this->conversations->findOrCreateActiveForContact($tenant, $contact->id);

        try {
            $message = TenantContext::withId($tenant->id, fn (): Message => Message::query()->create([
                'conversation_id' => $conversation->id,
                'provider_message_id' => $providerMessageId,
                'direction' => MessageDirection::Inbound,
                'type' => $type,
                'status' => MessageStatus::Delivered,
                'body' => $this->extractBody($type, $eventData),
                'media_mime' => $this->extractMediaMime($type, $eventData),
                'media_size' => $this->extractMediaSize($type, $eventData),
                'metadata' => $this->buildMetadata($eventData, $providerTimestamp),
                'delivered_at' => $this->providerTimestamp($providerTimestamp),
            ]));
        } catch (QueryException $e) {
            $existing = $this->findByProviderMessageId($tenant, $providerMessageId);

            if ($existing !== null) {
                return InboundMessageResult::existing($existing);
            }

            throw $e;
        }

        $reopened = $this->touchConversation($tenant, $conversation->id, $providerTimestamp);

        $this->auditLogger->record(
            action: 'message.received',
            data: [
                'tenant_id' => $tenant->id,
                'conversation_id' => $conversation->id,
                'provider_message_id' => $providerMessageId,
                'type' => $type->value,
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
     * Aplica un status update de Meta (sent/delivered/read/failed) al mensaje
     * existente por `provider_message_id`. No crea mensajes.
     *
     * @param  array<string, mixed>  $eventData
     */
    public function handleStatusUpdate(Tenant $tenant, array $eventData): void
    {
        $providerMessageId = isset($eventData['id']) && is_scalar($eventData['id']) ? (string) $eventData['id'] : '';
        $providerStatus = isset($eventData['status']) && is_scalar($eventData['status']) ? (string) $eventData['status'] : '';
        $providerTimestamp = isset($eventData['timestamp']) && is_scalar($eventData['timestamp'])
            ? (string) $eventData['timestamp']
            : null;

        if ($providerMessageId === '') {
            return;
        }

        $message = $this->findByProviderMessageId($tenant, $providerMessageId);

        if ($message === null) {
            Log::warning('messages.status_without_message', ['provider_message_id' => $providerMessageId]);

            return;
        }

        $status = MessageStatus::tryFrom($providerStatus);

        if ($status === null || $status === MessageStatus::Pending) {
            return;
        }

        $at = $this->providerTimestamp($providerTimestamp);

        $fill = ['status' => $status];

        if ($status->columnFor() !== null) {
            $fill[$status->columnFor()] = $at;
        }

        if ($status === MessageStatus::Failed) {
            $this->markConversationPending($tenant, $message->conversation_id);
        }

        $previous = $message->status->value;

        $message->forceFill($fill)->save();

        $this->auditLogger->record(
            action: 'message.status_updated',
            data: [
                'tenant_id' => $tenant->id,
                'provider_message_id' => $providerMessageId,
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

    /**
     * @param  array<string, mixed>  $eventData
     */
    private function extractBody(MessageType $type, array $eventData): ?string
    {
        return match ($type) {
            MessageType::Text => isset($eventData['text']['body']) && is_scalar($eventData['text']['body'])
                ? (string) $eventData['text']['body']
                : null,
            MessageType::Image, MessageType::Video, MessageType::Document => $this->stringOrNull($eventData, 'caption')
                ?? $this->stringOrNull($eventData, 'filename'),
            MessageType::Location => $this->stringOrNull($eventData, 'address')
                ?? $this->stringOrNull($eventData, 'name'),
            MessageType::Audio, MessageType::Interactive, MessageType::Template => null,
        };
    }

    /**
     * Busca un valor escalar dentro del payload del tipo de Meta (p. ej.
     * `text.body`, `image.caption`, `image.mime_type`).
     *
     * @param  array<string, mixed>  $eventData
     */
    private function stringOrNull(array $eventData, string $key): ?string
    {
        foreach ($eventData as $candidate) {
            if (is_array($candidate) && isset($candidate[$key]) && is_scalar($candidate[$key])) {
                return (string) $candidate[$key];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $eventData
     */
    private function extractMediaMime(MessageType $type, array $eventData): ?string
    {
        if (! in_array($type, [MessageType::Image, MessageType::Audio, MessageType::Video, MessageType::Document], true)) {
            return null;
        }

        return $this->stringOrNull($eventData, 'mime_type');
    }

    /**
     * @param  array<string, mixed>  $eventData
     */
    private function extractMediaSize(MessageType $type, array $eventData): ?int
    {
        if (! in_array($type, [MessageType::Image, MessageType::Audio, MessageType::Video, MessageType::Document], true)) {
            return null;
        }

        foreach ($eventData as $candidate) {
            if (is_array($candidate) && isset($candidate['size']) && is_numeric($candidate['size'])) {
                return (int) $candidate['size'];
            }
        }

        return null;
    }

    /**
     * Metadata del mensaje: `from`, timestamp del proveedor y el payload
     * específico del tipo (sin secretos; los payloads de Meta no los llevan).
     *
     * @param  array<string, mixed>  $eventData
     * @return array<string, mixed>
     */
    private function buildMetadata(array $eventData, ?string $providerTimestamp): array
    {
        $metadata = [
            'from' => $eventData['from'] ?? null,
            'provider_timestamp' => $providerTimestamp,
        ];

        foreach ([MessageType::Image, MessageType::Audio, MessageType::Video, MessageType::Document] as $mediaType) {
            if (isset($eventData[$mediaType->value]) && is_array($eventData[$mediaType->value])) {
                $metadata['media'] = $eventData[$mediaType->value];

                break;
            }
        }

        foreach ([MessageType::Location, MessageType::Interactive, MessageType::Template] as $objectType) {
            if (isset($eventData[$objectType->value]) && is_array($eventData[$objectType->value])) {
                $metadata[$objectType->value] = $eventData[$objectType->value];
            }
        }

        return $metadata;
    }
}
