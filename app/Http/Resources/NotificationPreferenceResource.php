<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serialización de preferencia de notificación (FASE 22 U4).
 *
 * Expone SOLO `email_notifications_enabled`.
 * NO expone: tenant_id, user_id, membership id.
 */
final class NotificationPreferenceResource extends JsonResource
{
    public function __construct(private readonly bool $emailEnabled)
    {
        parent::__construct(['email_notifications_enabled' => $emailEnabled]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'email_notifications_enabled' => $this->emailEnabled,
        ];
    }
}
