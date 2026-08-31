<?php

declare(strict_types=1);

namespace App\Events;

use App\Domain\Conversations\Enums\InboxConversationChangeKind;
use App\Domain\Conversations\Models\Conversation;
use App\Http\Resources\ConversationResource;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Evento tenant-wide para cambios del Inbox (ADR-053).
 *
 * Se emite en el canal privado `private-tenant.{tenantId}.inbox` después de
 * que la transacción DB haya sido confirmada (afterCommit). Todos los miembros
 * del tenant con `conversations.view` reciben el evento; el frontend lo
 * usa para upsert en la lista del Inbox sin depender de polling.
 *
 * El payload incluye la conversación serializada mediante `ConversationResource`
 * (seguro, sin datos cross-tenant) y el `kind` que identifica la operación.
 */
final class InboxConversationChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public bool $afterCommit = true;

    public readonly string $eventId;

    public function __construct(
        public readonly Conversation $conversation,
        public readonly InboxConversationChangeKind $kind,
    ) {
        $this->eventId = Str::uuid()->toString();
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel(sprintf(
                'tenant.%s.inbox',
                $this->conversation->tenant_id,
            )),
        ];
    }

    public function broadcastAs(): string
    {
        return 'InboxConversationChanged';
    }

    /**
     * @return array{event_id: string, kind: string, conversation: array<string, mixed>}
     */
    public function broadcastWith(): array
    {
        $conversation = TenantContext::withId(
            $this->conversation->tenant_id,
            fn (): Conversation => $this->conversation->load(['agent', 'contact']),
        );
        $conversationPayload = (new ConversationResource($conversation))->resolve();

        return [
            'event_id' => $this->eventId,
            'kind' => $this->kind->value,
            'conversation' => $conversationPayload,
        ];
    }
}
