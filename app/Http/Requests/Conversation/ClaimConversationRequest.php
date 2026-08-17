<?php

declare(strict_types=1);

namespace App\Http\Requests\Conversation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Claim manual: el agente destino siempre es el usuario autenticado.
 */
final class ClaimConversationRequest extends FormRequest
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
            'agent_id' => ['prohibited'],
            'tenant_id' => ['prohibited'],
        ];
    }
}
