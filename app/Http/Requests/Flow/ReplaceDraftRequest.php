<?php

declare(strict_types=1);

namespace App\Http\Requests\Flow;

use App\Domain\Flows\Enums\FlowNodeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Reemplazo atómico del grafo de un borrador (FASE 11).
 *
 * `nodes[]` + `connections[]` llegan completos (el frontend siempre envía el
 * grafo entero; el servicio lo valida y persiste en transacción). Los ids de
 * nodo los genera el cliente (UUID v4) y las conexiones los referencian. La
 * validación profunda por tipo de nodo la hace `FlowValidator` en el servicio
 * (422 FLOW_INVALID); aquí solo se valida la forma.
 */
final class ReplaceDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'base_updated_at' => ['nullable', 'date'],
            'nodes' => ['required', 'array', 'min:1'],
            'nodes.*.id' => ['required', 'uuid'],
            'nodes.*.type' => ['required', new Enum(FlowNodeType::class)],
            'nodes.*.name' => ['nullable', 'string', 'max:255'],
            'nodes.*.position_x' => ['nullable', 'integer'],
            'nodes.*.position_y' => ['nullable', 'integer'],
            'nodes.*.config' => ['nullable', 'array'],
            'nodes.*.is_start' => ['nullable', 'boolean'],
            'connections' => ['present', 'array'],
            'connections.*.source_node_id' => ['required', 'uuid'],
            'connections.*.target_node_id' => ['required', 'uuid'],
            'connections.*.label' => ['nullable', 'string', 'max:50'],
        ];
    }
}
