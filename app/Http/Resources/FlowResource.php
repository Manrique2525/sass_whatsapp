<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Flows\Models\Flow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Flow
 */
final class FlowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'chatbot_id' => $this->chatbot_id,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'config' => $this->config,
            'nodes' => $this->whenLoaded('nodes', fn (): mixed => FlowNodeResource::collection($this->nodes)),
            'connections' => $this->whenLoaded('connections', fn (): mixed => FlowConnectionResource::collection($this->connections)),
            'triggers' => $this->whenLoaded('triggers', fn (): mixed => TriggerResource::collection($this->triggers)),
            'chatbot' => $this->whenLoaded('chatbot', fn (): mixed => new ChatbotResource($this->chatbot)),
            'triggers_count' => $this->when(isset($this->triggers_count), fn (): int => (int) $this->triggers_count),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
