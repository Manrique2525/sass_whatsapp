<?php

declare(strict_types=1);

namespace App\Events;

use App\Domain\Notifications\Models\Notification;
use App\Http\Resources\NotificationResource;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Notificación in-app creada — canal personal (FASE 22 U5).
 *
 * Se emite en `private-tenant.{tenantId}.users.{userId}.notifications` después
 * del commit. Solo el usuario destinatario recibe el evento; ningún otro
 * miembro del tenant puede suscribirse a este canal.
 *
 * El payload es el `NotificationResource` (sin tenant_id ni user_id).
 */
final class NotificationCreated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public bool $afterCommit = true;

    public function __construct(
        public readonly Notification $notification,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel(sprintf(
                'tenant.%s.users.%d.notifications',
                $this->notification->tenant_id,
                $this->notification->user_id,
            )),
        ];
    }

    public function broadcastAs(): string
    {
        return 'NotificationCreated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'notification' => (new NotificationResource($this->notification))->resolve(),
        ];
    }
}
