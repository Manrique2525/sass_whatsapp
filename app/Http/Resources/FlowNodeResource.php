<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Flows\Enums\FlowNodeType;
use App\Domain\Flows\Models\FlowNode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FlowNode
 */
final class FlowNodeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'name' => $this->name,
            'position_x' => $this->position_x,
            'position_y' => $this->position_y,
            'config' => $this->safeConfig(),
            'is_start' => $this->is_start,
        ];
    }

    /**
     * Config del nodo para la API. El nodo `webhook` se sanea: solo se
     * exponen `method` y `url` (los headers/body pueden contener secretos y
     * nunca salen por API). El resto de tipos exponen su config completo.
     *
     * @return array<string, mixed>|null
     */
    private function safeConfig(): ?array
    {
        $config = $this->config;

        if ($this->type === FlowNodeType::Webhook) {
            return [
                'method' => is_array($config) ? ($config['method'] ?? 'POST') : 'POST',
                'url' => is_array($config) && isset($config['url']) ? (string) $config['url'] : '',
            ];
        }

        return $config;
    }
}
