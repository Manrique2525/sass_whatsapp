<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Flows\Models\Chatbot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Chatbot
 */
final class ChatbotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'flows_count' => $this->when(isset($this->flows_count), fn (): int => (int) $this->flows_count),
            'flows' => $this->whenLoaded('flows', fn (): mixed => FlowResource::collection($this->flows)),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
