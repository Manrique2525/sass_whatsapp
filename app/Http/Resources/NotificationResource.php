<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Notifications\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serialización de notificación in-app (FASE 22 U3).
 *
 * Campos expuestos: id, type, priority, title, body, data, read_at, created_at.
 * NO expone: tenant_id, user_id, internal audit metadata.
 */
/**
 * @mixin Notification
 */
final class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'priority' => $this->priority,
            'title' => $this->title,
            'body' => $this->body,
            'data' => $this->data,
            'read_at' => $this->read_at,
            'created_at' => $this->created_at,
        ];
    }
}
