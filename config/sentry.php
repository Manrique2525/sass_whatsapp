<?php

declare(strict_types=1);

use App\Domain\Billing\Exceptions\SubscriptionNotActiveException;
use App\Domain\Billing\Exceptions\SubscriptionNotFoundException;
use App\Domain\Billing\Exceptions\TenantQuotaExceededException;
use App\Infrastructure\Logging\SentryEventScrubber;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return [

    'dsn' => env('SENTRY_LARAVEL_DSN'),

    'release' => env('SENTRY_RELEASE'),

    'environment' => env('SENTRY_ENVIRONMENT'),

    'sample_rate' => env('SENTRY_SAMPLE_RATE') === null ? 1.0 : (float) env('SENTRY_SAMPLE_RATE'),

    'traces_sample_rate' => env('SENTRY_TRACES_SAMPLE_RATE') === null ? null : (float) env('SENTRY_TRACES_SAMPLE_RATE'),

    'profiles_sample_rate' => env('SENTRY_PROFILES_SAMPLE_RATE') === null ? null : (float) env('SENTRY_PROFILES_SAMPLE_RATE'),

    'send_default_pii' => false,

    'max_request_body_size' => 'none',

    'before_send' => [SentryEventScrubber::class, 'scrub'],

    'before_send_transaction' => [SentryEventScrubber::class, 'scrub'],

    'ignore_exceptions' => [
        ValidationException::class,
        AuthenticationException::class,
        AuthorizationException::class,
        ModelNotFoundException::class,
        NotFoundHttpException::class,
        TooManyRequestsHttpException::class,
        TenantQuotaExceededException::class,
        SubscriptionNotActiveException::class,
        SubscriptionNotFoundException::class,
    ],

    'ignore_transactions' => [
        '/up',
    ],

    'breadcrumbs' => [
        'logs' => true,
        'cache' => true,
        'livewire' => false,
        'sql_queries' => true,
        'sql_bindings' => false,
        'queue_info' => true,
        'command_info' => true,
        'http_client_requests' => false,
        'notifications' => true,
    ],

    'tracing' => [
        'queue_job_transactions' => false,
        'queue_jobs' => false,
        'sql_queries' => false,
        'sql_bindings' => false,
        'sql_origin' => false,
        'views' => false,
        'livewire' => false,
        'http_client_requests' => false,
        'cache' => false,
        'redis_commands' => false,
        'redis_origin' => false,
        'notifications' => false,
        'missing_routes' => false,
        'continue_after_response' => true,
        'gen_ai' => false,
        'gen_ai_invoke_agent' => false,
        'gen_ai_chat' => false,
        'gen_ai_execute_tool' => false,
        'gen_ai_embeddings' => false,
        'default_integrations' => true,
    ],

];
