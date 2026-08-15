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
            'config' => $this->redactedConfig(),
            'priority' => $this->priority,
            'active' => $this->active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * Config del trigger con los secretos redactados: `token_hash` del webhook
     * jamás se serializa (solo existe en BD; el token en claro se devuelve una
     * única vez en la creación).
     *
     * @return array<string, mixed>|null
     */
    private function redactedConfig(): ?array
    {
        $config = $this->config;

        if ($config === null) {
            return null;
        }

        unset($config['token_hash']);

        return $config;
    }
}
