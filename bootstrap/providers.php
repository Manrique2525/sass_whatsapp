<?php

declare(strict_types=1);

use App\Infrastructure\Logging\LoggingContextServiceProvider;
use App\Infrastructure\Logging\SentryQueueFailureServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    LoggingContextServiceProvider::class,
    SentryQueueFailureServiceProvider::class,
];
