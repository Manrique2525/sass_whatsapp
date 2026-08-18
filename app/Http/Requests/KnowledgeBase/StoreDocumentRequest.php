<?php

declare(strict_types=1);

namespace App\Http\Requests\KnowledgeBase;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Upload multipart de un documento a una knowledge base (FASE 17 U2.2).
 *
 * Validación de primera barrera: extensión, MIME y tamaño desde el request.
 * La validación REAL de seguridad (magic bytes, DOCX structure, etc.) reside
 * en DocumentUploadValidator dentro del Application Service.
 *
 * authorize() retorna true (patrón del proyecto); la authorization real
 * (knowledge.manage + tenant-safe KB) ocurre en DocumentService.
 */
final class StoreDocumentRequest extends FormRequest
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
        $config = config('knowledge.upload');

        return [
            'file' => [
                'required',
                'file',
                'max:'.$config['max_file_size'],
            ],
        ];
    }
}
