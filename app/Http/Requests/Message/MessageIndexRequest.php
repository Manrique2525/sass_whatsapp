<?php

declare(strict_types=1);

namespace App\Http\Requests\Message;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Paginación del historial de mensajes (FASE 10, ADR-033).
 *
 * El historial se devuelve siempre DESC (los más recientes primero); el
 * cliente puede navegar a páginas anteriores para cargar mensajes antiguos.
 * `per_page` está acotado a 100.
 */
final class MessageIndexRequest extends FormRequest
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
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
