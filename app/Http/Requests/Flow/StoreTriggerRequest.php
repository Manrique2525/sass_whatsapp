<?php

declare(strict_types=1);

namespace App\Http\Requests\Flow;

use App\Domain\Flows\Enums\FlowTriggerType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Alta de trigger de flujo (FASE 11).
 *
 * `keyword` es obligatorio cuando `type = keyword`; el resto de tipos no lo
 * usan en FASE 11. `priority` ordena triggers del mismo tipo.
 */
final class StoreTriggerRequest extends FormRequest
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
            'type' => ['required', new Enum(FlowTriggerType::class)],
            'keyword' => ['required_if:type,keyword', 'nullable', 'string', 'max:255'],
            'config' => ['nullable', 'array'],
            'priority' => ['nullable', 'integer'],
            'active' => ['nullable', 'boolean'],
        ];
    }
}
