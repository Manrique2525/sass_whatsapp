<?php

declare(strict_types=1);

namespace App\Http\Requests\KnowledgeBase;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Listado de knowledge bases del tenant (FASE 17 U2.1).
 *
 * No existe `tenant_id`: la pertenencia la resuelve `BelongsToTenant`. El
 * servicio autoriza `knowledge.view`.
 */
final class KnowledgeBaseIndexRequest extends FormRequest
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
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
