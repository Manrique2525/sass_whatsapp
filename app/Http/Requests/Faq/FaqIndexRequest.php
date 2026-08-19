<?php

declare(strict_types=1);

namespace App\Http\Requests\Faq;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Listado de FAQs del tenant (FASE 18 U3).
 */
final class FaqIndexRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
