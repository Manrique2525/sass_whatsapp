<?php

declare(strict_types=1);

namespace App\Http\Requests\Flow;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Alta de flujo dentro de un chatbot (FASE 11).
 *
 * `status` no se recibe: siempre nace `draft`. `config` es JSON libre del
 * motor (p. ej. `max_steps`).
 */
final class StoreFlowRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'config' => ['nullable', 'array'],
        ];
    }
}
