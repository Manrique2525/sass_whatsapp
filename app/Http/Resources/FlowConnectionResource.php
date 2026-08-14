<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Flows\Models\FlowConnection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FlowConnection
 */
final class FlowConnectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source_node_id' => $this->source_node_id,
            'target_node_id' => $this->target_node_id,
            'label' => $this->label,
        ];
    }
}
