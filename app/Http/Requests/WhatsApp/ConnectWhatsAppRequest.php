<?php

declare(strict_types=1);

namespace App\Http\Requests\WhatsApp;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de conexión de una cuenta de WhatsApp.
 *
 * La autorización (membresía + rol + permiso `whatsapp.manage`) la aplica
 * `WhatsAppConnectionService`, no este FormRequest. El `access_token` nunca se
 * devuelve en respuestas; se persiste cifrado.
 */
final class ConnectWhatsAppRequest extends FormRequest
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
            'whatsapp_business_account_id' => ['required', 'string', 'max:255'],
            'phone_number_id' => ['required', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:40'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'access_token' => ['required', 'string'],
        ];
    }
}
