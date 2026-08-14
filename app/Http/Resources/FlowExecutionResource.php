<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Flows\Models\FlowExecution;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FlowExecution
 */
final class FlowExecutionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'flow_id' => $this->flow_id,
            'conversation_id' => $this->conversation_id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'current_node_id' => $this->current_node_id,
            'variables' => $this->variables,
            'attempts' => $this->attempts,
            'last_inbound_message_id' => $this->last_inbound_message_id,
            'flow' => $this->whenLoaded('flow', fn (): mixed => new FlowResource($this->flow)),
            'conversation' => $this->whenLoaded('conversation', fn (): mixed => new ConversationResource($this->conversation)),
            'current_node' => $this->whenLoaded('currentNode', fn (): mixed => $this->currentNode !== null
                ? new FlowNodeResource($this->currentNode)
                : null),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
