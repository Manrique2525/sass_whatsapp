<?php

declare(strict_types=1);

namespace App\Http\Requests\Chatbot;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Actualización de chatbot (FASE 11). Campos opcionales: solo se aplican los
 * presentes; `description` admite null para limpiarlo.
 */
final class UpdateChatbotRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
