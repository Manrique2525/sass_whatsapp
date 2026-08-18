<?php

declare(strict_types=1);

namespace App\Http\Requests\KnowledgeBase;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Alta de knowledge base (FASE 17 U2.1).
 *
 * No existe `tenant_id`: la pertenencia la resuelve `BelongsToTenant`. El
 * servicio autoriza `knowledge.manage` y devuelve 404/403/409 según patrón.
 *
 * La unicidad real es `(tenant_id, name) WHERE deleted_at IS NULL` (backstop
 * DB). Esta validación ofrece feedback temprano al usuario.
 */
final class StoreKnowledgeBaseRequest extends FormRequest
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
