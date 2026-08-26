<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging\Processors;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Monolog\LogRecord;

/**
 * Monolog processor que inyecta request_id a cada log line.
 *
 * Resuelve el request_id desde:
 * 1. Request::attributes (HTTP requests — set by RequestCorrelationId middleware)
 * 2. Current job payload (queue jobs — propagated via JobCorrelationMiddleware)
 * 3. Shared context fallback
 *
 * Seguro para workers: no cachea entre requests/jobs.
 */
final class RequestContextProcessor
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $requestId = $this->resolveRequestId();

        if ($requestId !== null) {
            $record->extra['request_id'] = $requestId;
        }

        return $record;
    }

    private function resolveRequestId(): ?string
    {
        // 1. From current HTTP request attributes (set by RequestCorrelationId middleware)
        try {
            /** @var Request|null $request */
            $request = request();
            if ($request instanceof Request) {
                $id = $request->attributes->get('request_id');
                if ($id !== null) {
                    return (string) $id;
                }
            }
        } catch (\Throwable) {
            // Not in HTTP context
        }

        // 2. From current job payload (set by JobCorrelationMiddleware)
        try {
            $job = app('queue.worker.job');
            if ($job !== null && method_exists($job, 'payload')) {
                $payload = $job->payload();
                $extra = $payload['request_id'] ?? $payload['properties']['request_id'] ?? null;
                if ($extra !== null) {
                    return (string) $extra;
                }
            }
        } catch (\Throwable) {
            // Not in job context
        }

        return null;
    }
}
