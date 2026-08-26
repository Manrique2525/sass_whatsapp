<?php

declare(strict_types=1);

use App\Infrastructure\Logging\LoggingContextServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    LoggingContextServiceProvider::class,
];
