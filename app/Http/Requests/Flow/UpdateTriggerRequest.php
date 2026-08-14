<?php

declare(strict_types=1);

namespace App\Http\Requests\Flow;

use App\Domain\Flows\Enums\FlowTriggerType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Actualización de trigger (FASE 11). Campos opcionales; `keyword` se exige
 * solo si llega `type = keyword`.
 */
final class UpdateTriggerRequest extends FormRequest
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
            'type' => ['sometimes', new Enum(FlowTriggerType::class)],
            'keyword' => ['required_if:type,keyword', 'nullable', 'string', 'max:255'],
            'config' => ['nullable', 'array'],
            'priority' => ['sometimes', 'integer'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
