<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Flows\Models\Trigger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Trigger
 */
final class TriggerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'flow_id' => $this->flow_id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'keyword' => $this->keyword,
            'config' => $this->config,
            'priority' => $this->priority,
            'active' => $this->active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
