<?php

declare(strict_types=1);

namespace App\Http\Requests\Conversation;

use App\Domain\Conversations\Enums\ConversationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Actualización de conversación (FASE 8, ADR-031). Parcial.
 *
 * `status` valida la transición en el servicio (máquina de estados;
 * mismas transiciones = no-op). `context` se fusiona por claves.
 */
final class UpdateConversationRequest extends FormRequest
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
            'status' => ['nullable', new Enum(ConversationStatus::class)],
            'context' => ['nullable', 'array'],
        ];
    }
}
