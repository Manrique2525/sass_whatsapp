<?php

declare(strict_types=1);

namespace App\Http\Requests\Faq;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Actualización parcial de FAQ (FASE 18 U3).
 *
 * Todos los campos son opcionales (PATCH).
 */
final class UpdateFaqRequest extends FormRequest
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
            'question' => ['nullable', 'string', 'max:500'],
            'answer' => ['nullable', 'string', 'max:4096'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:100'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
        ];
    }
}
