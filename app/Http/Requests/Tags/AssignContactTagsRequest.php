<?php

declare(strict_types=1);

namespace App\Http\Requests\Tags;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Asignación batch de tags a un contacto (FASE 20 U3).
 *
 * Validación estricta: solo UUIDs estables, max 20, sin duplicados.
 * El tenant_id viene del TenantContext; el contact_id de la ruta.
 */
final class AssignContactTagsRequest extends FormRequest
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
            'tag_ids' => ['required', 'array', 'min:1', 'max:20'],
            'tag_ids.*' => ['required', 'string', 'uuid', 'distinct'],
        ];
    }
}
