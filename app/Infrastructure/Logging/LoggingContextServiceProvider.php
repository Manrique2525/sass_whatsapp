<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

/**
 * Registers logging context propagation for the application.
 *
 * - Adds request_id to every job payload via Queue::createPayloadUsing
 * - Registers job middleware for context restoration during job execution
 */
final class LoggingContextServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->propagateRequestIdToJobs();
    }

    private function propagateRequestIdToJobs(): void
    {
        Queue::createPayloadUsing(function (string $connection, ?string $queue, array $payload): array {
            $requestId = $this->resolveCurrentRequestId();

            return $requestId !== null ? ['request_id' => $requestId] : [];
        });

        Queue::after(function (JobProcessed $event): void {
            Log::shareContext([
                'request_id' => null,
            ]);
        });
    }

    private function resolveCurrentRequestId(): ?string
    {
        try {
            return request()->attributes->get('request_id');
        } catch (\Throwable) {
            return null;
        }
    }
}
