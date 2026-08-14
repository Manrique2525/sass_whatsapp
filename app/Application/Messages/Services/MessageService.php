<?php

declare(strict_types=1);

namespace App\Application\Messages\Services;

use App\Application\Audit\Services\AuditLogger;
use App\Application\Contacts\Services\ContactService;
use App\Application\Conversations\Services\ConversationService;
use App\Domain\Conversations\Enums\ConversationStatus;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Messages\Enums\MessageDirection;
use App\Domain\Messages\Enums\MessageStatus;
use App\Domain\Messages\Enums\MessageType;
use App\Domain\Messages\Exceptions\UnsupportedMessageTypeException;
use App\Domain\Messages\Models\Message;
use App\Domain\Tenants\Models\Tenant;
use App\Infrastructure\Tenancy\TenantContext;
use App\Jobs\SendWhatsAppMessage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

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
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly ContactService $contacts,
        private readonly ConversationService $conversations,
    ) {}

    /**
     * Persiste un mensaje entrante de Meta. Devuelve null si el payload no es
     * procesable (falta id/from); lanza UnsupportedMessageTypeException si el
     * tipo no se persiste; devuelve el mensaje existente si ya se procesó.
     *
     * @param  array<string, mixed>  $eventData
     */
    public function handleInboundMessage(Tenant $tenant, array $eventData): ?Message
    {
        $providerMessageId = isset($eventData['id']) && is_scalar($eventData['id']) ? (string) $eventData['id'] : '';
        $from = isset($eventData['from']) && is_scalar($eventData['from']) ? (string) $eventData['from'] : '';
        $providerType = isset($eventData['type']) && is_scalar($eventData['type']) ? (string) $eventData['type'] : '';
        $providerTimestamp = isset($eventData['timestamp']) && is_scalar($eventData['timestamp'])
            ? (string) $eventData['timestamp']
            : null;

        if ($providerMessageId === '' || $from === '') {
            Log::warning('messages.inbound_missing_fields', ['provider_message_id' => $providerMessageId]);

            return null;
        }

        $existing = $this->findByProviderMessageId($tenant, $providerMessageId);

        if ($existing !== null) {
            return $existing;
        }

        $type = MessageType::fromProvider($providerType);

        if ($type === null) {
            throw new UnsupportedMessageTypeException($providerType);
        }

        $contact = $this->contacts->findOrCreateForPhone($tenant, $from);
        $conversation = $this->conversations->findOrCreateActiveForContact($tenant, $contact->id);

        TenantContext::setId($tenant->id);

        try {
            $message = Message::query()->create([
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
            ]);
        } catch (QueryException $e) {
            $existing = $this->findByProviderMessageId($tenant, $providerMessageId);

            if ($existing !== null) {
                return $existing;
            }

            throw $e;
        } finally {
            TenantContext::clear();
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

        return $message;
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
    }

    /**
     * Crea un mensaje saliente (texto) en `pending` y encola su envío.
     */
    public function createOutbound(Tenant $tenant, Conversation $conversation, string $body): Message
    {
        TenantContext::setId($tenant->id);

        try {
            $message = Message::query()->create([
                'conversation_id' => $conversation->id,
                'direction' => MessageDirection::Outbound,
                'type' => MessageType::Text,
                'status' => MessageStatus::Pending,
                'body' => $body,
                'metadata' => ['text' => $body],
            ]);
        } finally {
            TenantContext::clear();
        }

        dispatch((new SendWhatsAppMessage($tenant->id, $conversation->id, $message->id))->forTenant($tenant->id));

        return $message;
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
     * reabre si estaba resuelta/archivada. Devuelve si se reabrió.
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
