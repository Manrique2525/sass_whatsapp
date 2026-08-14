<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Messages\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Message
 */
final class MessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'provider_message_id' => $this->provider_message_id,
            'direction' => $this->direction->value,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'body' => $this->body,
            'media_url' => $this->media_url,
            'media_mime' => $this->media_mime,
            'media_size' => $this->media_size,
            'metadata' => $this->metadata,
            'sent_at' => $this->sent_at,
            'delivered_at' => $this->delivered_at,
            'read_at' => $this->read_at,
            'failed_at' => $this->failed_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
