<?php

declare(strict_types=1);

namespace App\Http\Requests\Template;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de envío de un template a una conversación.
 *
 * `variables` es una lista ordenada de valores (0..N según el schema); la
 * validación de cardinalidad exacta contra el BODY/HEADER la hace el dominio
 * (`TemplateVariableValidator`) ANTES de llamar al provider.
 */
final class SendTemplateRequest extends FormRequest
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
            'template_id' => ['required', 'uuid'],
            'variables' => ['sometimes', 'array'],
            'variables.*' => ['required', 'string'],
        ];
    }
}
