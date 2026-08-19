<?php

declare(strict_types=1);

namespace App\Http\Requests\Faq;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Creación de FAQ (FASE 18 U3).
 *
 * `normalized_question` se calcula server-side (FaqQuestionNormalizer).
 * `tenant_id` viene del TenantContext.
 */
final class StoreFaqRequest extends FormRequest
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
            'question' => ['required', 'string', 'max:500'],
            'answer' => ['required', 'string', 'max:4096'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:100'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
        ];
    }
}
