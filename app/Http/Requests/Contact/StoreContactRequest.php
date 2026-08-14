<?php

declare(strict_types=1);

namespace App\Http\Requests\Contact;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Alta de contacto (FASE 7, ADR-030).
 *
 * `phone` admite formato libre (`+`, dígitos, espacios, () .-) pero DEBE
 * tener entre 7 y 15 dígitos; `ContactService` lo normaliza a E.164 canónico.
 * No existe `tenant_id`: la pertenencia la resuelve TenantContext.
 */
final class StoreContactRequest extends FormRequest
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
            'phone' => [
                'required',
                'string',
                'max:40',
                'regex:/^\+?[0-9\s().\-]+$/',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $digits = strlen((string) preg_replace('/\D/', '', (string) $value));

                    if ($digits < 7 || $digits > 15) {
                        $fail('El teléfono debe tener entre 7 y 15 dígitos.');
                    }
                },
            ],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'avatar_url' => ['nullable', 'string', 'url', 'max:2048'],
            'metadata' => ['nullable', 'array'],
            'provider_contact_id' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'El teléfono solo puede contener dígitos, +, espacios, paréntesis, puntos o guiones.',
        ];
    }
}
