<?php

declare(strict_types=1);

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Actualización del perfil de negocio del tenant activo.
 *
 * Todos los campos son opcionales (la actualización es parcial). No existe
 * regla ni campo `tenant_id`: la pertenencia la resuelve TenantContext +
 * autorización, nunca el cuerpo de la petición.
 */
final class UpdateBusinessProfileRequest extends FormRequest
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
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'string', 'url', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'working_hours' => ['nullable', 'array', 'max:7'],
            'working_hours.*.day' => ['required_with:working_hours', 'string', 'in:mon,tue,wed,thu,fri,sat,sun'],
            'working_hours.*.open' => ['nullable', 'string', 'regex:/^([01]?\d|2[0-3]):[0-5]\d$/'],
            'working_hours.*.close' => ['nullable', 'string', 'regex:/^([01]?\d|2[0-3]):[0-5]\d$/'],
            'working_hours.*.closed' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'working_hours.*.day.required_with' => 'Cada horario debe indicar su día.',
            'working_hours.*.day.in' => 'El día debe ser mon, tue, wed, thu, fri, sat o sun.',
            'working_hours.*.open.regex' => 'La hora de apertura debe tener formato HH:mm (24h).',
            'working_hours.*.close.regex' => 'La hora de cierre debe tener formato HH:mm (24h).',
        ];
    }
}
