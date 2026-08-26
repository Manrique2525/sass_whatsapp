<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Sentry\SentrySdk;
use Sentry\Severity;
use Sentry\State\Scope;

/**
 * Registers queue failure capture for Sentry.
 *
 * When a job exhausts retries, this captures the exception as a Sentry
 * event with job class, queue name, attempt count, request_id, and
 * tenant_id — without including the serialized job payload.
 *
 * The Sentry Laravel SDK does NOT auto-capture queue failures via
 * Queue::failing(), only finishes tracing spans on JobExceptionOccurred.
 * This provider fills that gap.
 */
final class SentryQueueFailureServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (config('sentry.dsn') === null) {
            return;
        }

        Queue::failing(function (JobFailed $event): void {
            try {
                $this->captureFailedJob($event);
            } catch (\Throwable) {
                // Fail-safe: never break queue processing
            }
        });
    }

    private function captureFailedJob(JobFailed $event): void
    {
        $hub = SentrySdk::getCurrentHub();
        $hub->withScope(function (Scope $scope) use ($hub, $event): void {
            $scope->setLevel(Severity::error());
            $scope->setTag('job_class', $event->job->resolveName());
            $scope->setTag('queue', $event->job->getQueue());
            $scope->setTag('job_id', $event->job->getJobId());

            // Attempt count if available via reflection (property is protected)
            try {
                $reflection = new \ReflectionProperty($event->job, 'attempts');
                $reflection->setValue($event->job);
                $scope->setTag('job_attempts', (string) $reflection->getValue($event->job));
            } catch (\Throwable) {
                // Not all job implementations have 'attempts' property
            }

            // Add request_id from job payload if present (U1 propagation)
            $payload = $event->job->payload();
            $requestId = $payload['request_id'] ?? null;
            if ($requestId !== null) {
                $scope->setTag('request_id', (string) $requestId);
            }

            // Add tenant_id from job payload if present (TenantAwareJob)
            $tenantId = $payload['tenant_id'] ?? null;
            if ($tenantId !== null) {
                $scope->setTag('tenant_id', (string) $tenantId);
            }

            $scope->setExtra('job_max_tries', $event->job->maxTries());
            $scope->setExtra('job_timeout', $event->job->timeout());

            $hub->captureException($event->exception);
        });
    }
}
