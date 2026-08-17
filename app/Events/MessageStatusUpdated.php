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
 * Cambio de estado de un mensaje (sent/delivered/read/failed) reportado por
 * Meta (status webhook) o por el job de envío del outbox.
 *
 * Canal privado por conversación (ADR-022). El payload incluye el mensaje
 * completo ya actualizado; el cliente reemplaza el mensaje existente.
 */
final class MessageStatusUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /** El broadcast encolado nunca puede ejecutarse antes del commit. */
    public bool $afterCommit = true;

    public function __construct(
        public readonly Message $message,
        public readonly string $previousStatus,
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
        return 'MessageStatusUpdated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'message' => (new MessageResource($this->message))->resolve(),
            'previous_status' => $this->previousStatus,
        ];
    }
}
