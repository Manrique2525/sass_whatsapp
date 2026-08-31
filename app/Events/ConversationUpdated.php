<?php

declare(strict_types=1);

namespace App\Events;

use App\Domain\Conversations\Models\Conversation;
use App\Http\Resources\ConversationResource;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * La conversación cambió (estado, asignación/transferencia, bot pausado o
 * timestamps de interacción). El canal es el de la propia conversación
 * (ADR-022); el cliente refresca el header y el item de la lista.
 *
 * El recurso exige `contact` y `agent` cargados; quien emite el evento debe
 * asegurarse de cargarlos previamente.
 */
final class ConversationUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /** El broadcast encolado nunca puede ejecutarse antes del commit. */
    public bool $afterCommit = true;

    public function __construct(
        public readonly Conversation $conversation,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel(sprintf(
                'tenant.%s.conversations.%s',
                $this->conversation->tenant_id,
                $this->conversation->id,
            )),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ConversationUpdated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $conversation = TenantContext::withId(
            $this->conversation->tenant_id,
            fn (): Conversation => $this->conversation->load(['agent', 'contact']),
        );
        $conversationPayload = (new ConversationResource($conversation))->resolve();

        return [
            'conversation' => $conversationPayload,
        ];
    }
}
