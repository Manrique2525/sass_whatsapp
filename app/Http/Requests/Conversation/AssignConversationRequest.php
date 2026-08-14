<?php

declare(strict_types=1);

namespace App\Http\Requests\Conversation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Asignación/transferencia de conversación a un agente (FASE 8, ADR-031).
 *
 * `agent_id` referencia `users.id`; el servicio valida que el usuario sea
 * miembro ACTIVO del tenant (422 AGENT_NOT_IN_TENANT si no lo es).
 */
final class AssignConversationRequest extends FormRequest
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
            'agent_id' => ['required', 'integer'],
        ];
    }
}
