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

];
