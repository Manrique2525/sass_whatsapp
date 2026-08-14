<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Conversations\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Conversation
 */
final class ConversationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'contact' => $this->whenLoaded('contact', fn () => new ContactResource($this->contact)),
            'agent' => $this->relationLoaded('agent') && $this->agent !== null
                ? [
                    'id' => $this->agent->id,
                    'name' => $this->agent->name,
                    'email' => $this->agent->email,
                ]
                : null,
            'last_message_at' => $this->last_message_at,
            'last_interaction_at' => $this->last_interaction_at,
            'last_message' => $this->whenLoaded('lastMessage', fn () => $this->lastMessage !== null
                ? new MessageResource($this->lastMessage)
                : null),
            'auto_assigned' => $this->auto_assigned,
            'bot_paused' => $this->bot_paused,
            'context' => $this->context,
            'flow_execution_id' => $this->flow_execution_id,
            'participants' => $this->whenLoaded('participants', fn (): array => $this->participants
                ->map(fn ($p): array => [
                    'id' => $p->id,
                    'user' => $p->relationLoaded('user') && $p->user !== null ? [
                        'id' => $p->user->id,
                        'name' => $p->user->name,
                    ] : null,
                    'role' => $p->role,
                    'joined_at' => $p->joined_at,
                    'left_at' => $p->left_at,
                ])
                ->values()
                ->all()),
            'assignments' => $this->whenLoaded('assignments', fn (): array => $this->assignments
                ->map(fn ($a): array => [
                    'id' => $a->id,
                    'agent' => $a->relationLoaded('agent') && $a->agent !== null ? [
                        'id' => $a->agent->id,
                        'name' => $a->agent->name,
                    ] : null,
                    'assigned_by' => $a->assigned_by,
                    'assigned_at' => $a->assigned_at,
                    'unassigned_at' => $a->unassigned_at,
                    'reason' => $a->reason,
                ])
                ->values()
                ->all()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
