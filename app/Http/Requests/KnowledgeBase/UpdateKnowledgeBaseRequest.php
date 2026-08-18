<?php

declare(strict_types=1);

namespace App\Http\Requests\KnowledgeBase;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Actualización parcial de knowledge base (FASE 17 U2.1).
 *
 * Todos los campos son opcionales (PATCH). La unicidad real es
 * `(tenant_id, name) WHERE deleted_at IS NULL` (backstop DB).
 */
final class UpdateKnowledgeBaseRequest extends FormRequest
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
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
