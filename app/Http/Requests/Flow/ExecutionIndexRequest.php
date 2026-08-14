<?php

declare(strict_types=1);

namespace App\Http\Requests\Flow;

use App\Domain\Flows\Enums\FlowExecutionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Filtros del listado de ejecuciones (FASE 11).
 *
 * `status` filtra por estado de ejecución; `flow_id`/`chatbot_id` acotan por
 * flujo o chatbot (uuid); `per_page` acotado a 100.
 */
final class ExecutionIndexRequest extends FormRequest
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
            'status' => ['nullable', 'string', new Enum(FlowExecutionStatus::class)],
            'flow_id' => ['nullable', 'uuid'],
            'chatbot_id' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
