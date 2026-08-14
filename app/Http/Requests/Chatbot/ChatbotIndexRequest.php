<?php

declare(strict_types=1);

namespace App\Http\Requests\Chatbot;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Filtros del listado de chatbots (FASE 11).
 *
 * `search` filtra por nombre; `per_page` acotado a 100 (protección contra
 * abuso del paginador).
 */
final class ChatbotIndexRequest extends FormRequest
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
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
