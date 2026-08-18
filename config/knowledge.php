<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Document Upload Configuration (FASE 17 U2.2)
    |--------------------------------------------------------------------------
    |
    | Límites y formatos aceptados para el upload de documentos de knowledge
    | base. El storage_disk se resuelve por env override; la validación real
    | de MIME y magic bytes vive en DocumentUploadValidator.
    |
    */

    'upload' => [

        'allowed_extensions' => ['pdf', 'docx', 'txt'],

        'allowed_mime_types' => [
            'application/pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'text/plain',
        ],

        'max_file_size' => 10 * 1024 * 1024, // 10 MB

        'storage_disk' => env('KNOWLEDGE_STORAGE_DISK', 'minio'),

        'storage_prefix' => 'knowledge',

    ],

];
