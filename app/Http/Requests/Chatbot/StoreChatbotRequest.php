<?php

declare(strict_types=1);

namespace App\Http\Requests\Chatbot;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Alta de chatbot (FASE 11).
 *
 * No existe `tenant_id`: la pertenencia la resuelve `BelongsToTenant`. El
 * servicio autoriza `flows.manage` y devuelve 404/403/409 según patrón.
 */
final class StoreChatbotRequest extends FormRequest
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
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
