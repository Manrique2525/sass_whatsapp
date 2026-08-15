<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Flows\ValueObjects\VariableDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Definición de una variable del catálogo de un flujo (FASE 13, UNIDAD 3).
 *
 * Expone SOLO la definición derivada (`VariableCatalogService`): nunca valores
 * runtime, ni `tenant_id`, ni config de nodos, ni secretos (webhook headers/
 * body, tokens, credenciales).
 *
 * @mixin VariableDefinition
 */
final class VariableDefinitionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'namespace' => $this->namespace,
            'source' => $this->source,
            'type' => $this->type->value,
            'default' => $this->default,
            'writable' => $this->writable,
        ];
    }
}
