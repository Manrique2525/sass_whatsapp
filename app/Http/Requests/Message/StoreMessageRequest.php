<?php

declare(strict_types=1);

namespace App\Http\Requests\Message;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Envío de un mensaje de texto saliente (FASE 10, ADR-033).
 *
 * Solo se permite texto por ahora (el envío de medios será una fase posterior).
 * `body` en blanco (solo espacios) se rechaza.
 */
final class StoreMessageRequest extends FormRequest
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
            'body' => ['required', 'string', 'max:4096'],
        ];
    }
}
