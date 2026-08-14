<?php

declare(strict_types=1);

namespace App\Http\Requests\Flow;

use App\Domain\Flows\Enums\FlowStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Filtros del listado de flujos de un chatbot (FASE 11).
 *
 * `status` filtra por estado de la máquina de estados; `per_page` acotado a
 * 100.
 */
final class FlowIndexRequest extends FormRequest
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
            'status' => ['nullable', 'string', new Enum(FlowStatus::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
