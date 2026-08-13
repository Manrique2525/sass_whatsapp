<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Actualización de datos del tenant activo.
 *
 * Nota: no existe regla ni campo `tenant_id` — el tenant destino se resuelve
 * por ruta y autorización, nunca desde el cuerpo de la petición.
 */
final class UpdateTenantRequest extends FormRequest
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
            'timezone' => ['required', 'string', 'timezone'],
            'locale' => ['required', 'string', 'in:en,es'],
        ];
    }
}
