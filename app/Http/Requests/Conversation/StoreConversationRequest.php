<?php

declare(strict_types=1);

namespace App\Http\Requests\Conversation;

use App\Domain\Conversations\Enums\ConversationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Alta de conversación (FASE 8, ADR-031).
 *
 * No existe `tenant_id`: la pertenencia la resuelve TenantContext. `contact_id`
 * debe apuntar a un contacto del MISMO tenant (el servicio lo valida y devuelve
 * 404 si no existe o es cross-tenant). `status` opcional (default `open`);
 * `context` JSON libre para el motor de flujos.
 */
final class StoreConversationRequest extends FormRequest
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
            'contact_id' => ['required', 'uuid'],
            'status' => ['nullable', new Enum(ConversationStatus::class)],
            'bot_paused' => ['nullable', 'boolean'],
            'context' => ['nullable', 'array'],
        ];
    }
}
