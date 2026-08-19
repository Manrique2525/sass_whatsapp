<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider
    |--------------------------------------------------------------------------
    |
    | The name of the default AI provider to use for generating responses.
    | This provider will be resolved from the service container.
    |
    */

    'default' => env('AI_PROVIDER', 'openai'),

    /*
    |--------------------------------------------------------------------------
    | AI Providers
    |--------------------------------------------------------------------------
    |
    | Configuration for each AI provider. The "default" provider references
    | one of these configurations by its key. Each provider must implement
    | App\Domain\AI\Contracts\AIProviderInterface.
    |
    */

    'providers' => [

        'openai' => [

            'api_key' => env('OPENAI_API_KEY', ''),

            'model' => env('AI_MODEL', 'gpt-4o-mini'),

            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),

            'timeout' => (int) env('AI_TIMEOUT', 15),

            'max_retries' => (int) env('AI_MAX_RETRIES', 1),

            'max_tokens' => (int) env('AI_MAX_TOKENS', 500),

        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | AI Fallback Message
    |--------------------------------------------------------------------------
    |
    | Mensaje por defecto cuando el proveedor de IA falla y el nodo no define
    | un fallback_message propio. Si es null, el output_variable queda sin
    | valor y el flow continúa.
    |
    */

    'fallback_message' => env('AI_FALLBACK_MESSAGE'),

    /*
    |--------------------------------------------------------------------------
    | Embedding Provider (FASE 17 U3.1)
    |--------------------------------------------------------------------------
    |
    | Configuración del proveedor de embeddings vectoriales para búsqueda
    | semántica (RAG). Separado del proveedor de chat por SRP/ISP.
    |
    */

    'embedding' => [

        'default' => env('AI_EMBEDDING_PROVIDER', 'openai'),

        'providers' => [

            'openai' => [

                'api_key' => env('OPENAI_API_KEY', ''),

                'model' => env('AI_EMBEDDING_MODEL', 'text-embedding-3-small'),

                'dimensions' => (int) env('AI_EMBEDDING_DIMENSIONS', 1536),

                'max_batch_size' => (int) env('AI_EMBEDDING_MAX_BATCH', 50),

                'timeout' => (int) env('AI_EMBEDDING_TIMEOUT', 30),

                'max_retries' => (int) env('AI_EMBEDDING_MAX_RETRIES', 2),

            ],

        ],

    ],

];
