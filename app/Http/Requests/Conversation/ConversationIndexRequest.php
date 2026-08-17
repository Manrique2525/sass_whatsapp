<?php

declare(strict_types=1);

namespace App\Http\Requests\Conversation;

use App\Domain\Conversations\Enums\ConversationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Filtros del listado de conversaciones (FASE 8, ADR-031).
 *
 * `status` acepta un único estado de la máquina de estados; `agent_id` filtra
 * por el agente asignado; `search` busca en el contacto (nombre/teléfono/email).
 * `per_page` está acotado a 100 (protección contra abuso del paginador).
 */
final class ConversationIndexRequest extends FormRequest
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
            'status' => ['nullable', 'string', new Enum(ConversationStatus::class)],
            'agent_id' => ['nullable', 'integer'],
            'scope' => ['nullable', 'string', 'in:all,mine,unassigned'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
