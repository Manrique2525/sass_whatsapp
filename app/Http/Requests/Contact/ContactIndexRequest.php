<?php

declare(strict_types=1);

namespace App\Http\Requests\Contact;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Filtros del listado de contactos.
 *
 * `phone` se filtra por prefijo sobre el valor normalizado (E.164 con `+`):
 * el frontend puede enviar dígitos sueltos o un número completo. `per_page`
 * está acotado a 100 (protección contra abuso del paginador).
 */
final class ContactIndexRequest extends FormRequest
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
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
