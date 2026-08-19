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

    /*
    |--------------------------------------------------------------------------
    | Text Extraction Configuration (FASE 17 U2.3)
    |--------------------------------------------------------------------------
    |
    | Límites para extracción de texto y chunking de documentos knowledge.
    |
    */

    'extraction' => [

        'max_extracted_text_size' => 500 * 1024, // 500K chars

        'docx_max_zip_entries' => 500,

        'docx_max_uncompressed_bytes' => 50 * 1024 * 1024, // 50 MB

        'docx_max_compression_ratio' => 100,

    ],

    'chunking' => [

        'max_chunk_length' => 1500, // chars

        'chunk_overlap' => 200, // chars from end of previous chunk

        'min_chunk_length' => 50, // merge if smaller

        'max_chunks_per_document' => 500,

    ],

    'processing' => [

        'tries' => 3,

        'backoff' => [30, 60], // seconds between retries

    ],

];
