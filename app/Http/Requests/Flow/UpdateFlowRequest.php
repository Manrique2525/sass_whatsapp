<?php

declare(strict_types=1);

namespace App\Http\Requests\Flow;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Actualización de metadatos de un flujo (FASE 11).
 *
 * Solo aplica los campos presentes. Un flujo `published` se rechaza en el
 * servicio con `409 FLOW_PUBLISHED` (deactivar antes de editar). El grafo no
 * se toca aquí: va por `PUT .../draft`.
 */
final class UpdateFlowRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'config' => ['nullable', 'array'],
        ];
    }
}
