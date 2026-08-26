<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use Illuminate\Support\Facades\Log;

/**
 * Queue job middleware that restores request correlation context
 * from the job payload and sets Log shared context.
 *
 * This ensures that logs emitted during job execution include the
 * originating request_id (if available) and prevents context leakage
 * between jobs in the same worker process.
 */
final class JobCorrelationMiddleware
{
    /**
     * Handle the job.
     */
    public function handle(object $job, callable $next): void
    {
        $previousRequestId = $this->getCurrentRequestId();

        try {
            $payload = $job->payload();
            $requestId = $payload['request_id'] ?? null;

            if ($requestId !== null) {
                Log::shareContext([
                    'request_id' => (string) $requestId,
                ]);
            }

            $next($job);
        } finally {
            if ($previousRequestId !== null) {
                Log::shareContext([
                    'request_id' => $previousRequestId,
                ]);
            } else {
                Log::shareContext([
                    'request_id' => null,
                ]);
            }
        }
    }

    private function getCurrentRequestId(): ?string
    {
        try {
            return request()->attributes->get('request_id');
        } catch (\Throwable) {
            return null;
        }
    }
}
