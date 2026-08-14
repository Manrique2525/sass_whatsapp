<?php

declare(strict_types=1);

namespace App\Events;

use App\Domain\Messages\Models\Message;
use App\Http\Resources\MessageResource;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Nuevo mensaje persistido (inbound del webhook o outbound del usuario).
 *
 * Canal privado `tenant.{tenantId}.conversations.{conversationId}` (ADR-022:
 * sin comodines, por recurso). El payload es el `MessageResource` completo
 * (dirección, tipo, estado y timestamps).
 */
final class MessageCreated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly Message $message,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel(sprintf(
                'tenant.%s.conversations.%s',
                $this->message->tenant_id,
                $this->message->conversation_id,
            )),
        ];
    }

    public function broadcastAs(): string
    {
        return 'MessageCreated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'message' => (new MessageResource($this->message))->resolve(),
        ];
    }
}
