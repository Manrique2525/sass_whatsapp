<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Scheduler Heartbeat Max Age
    |--------------------------------------------------------------------------
    |
    | Maximum age in seconds for the scheduler heartbeat before it's considered
    | stale. The scheduler writes a timestamp every minute; if the timestamp
    | is older than this value, the readiness probe reports 'stale'.
    |
    | Default: 120 (2 minutes — tolerates one missed heartbeat + clock skew).
    |
    */

    'scheduler_heartbeat_max_age_seconds' => (int) env('SCHEDULER_HEARTBEAT_MAX_AGE', 120),

    /*
    |--------------------------------------------------------------------------
    | Audit Log Retention
    |--------------------------------------------------------------------------
    |
    | Number of days to retain audit_log records. Records older than this
    | are pruned daily via the audit:prune artisan command.
    |
    | Default: 90 days.
    |
    */

    'audit_log_retention_days' => (int) env('AUDIT_LOG_RETENTION_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Failed Jobs Retention
    |--------------------------------------------------------------------------
    |
    | Number of days to retain failed_jobs records. Records older than this
    | are pruned daily via the queue:prune-failed artisan command.
    |
    | Default: 30 days.
    |
    */

    'failed_jobs_retention_days' => (int) env('FAILED_JOBS_RETENTION_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Lightweight Metrics Counters
    |--------------------------------------------------------------------------
    |
    | FASE 31 U6 — contadores y gauges ligeros (MetricsRecorder) sobre el cache
    | compartido (Redis en producción), claves `observability:metrics:*`.
    |
    | Fail-safe por diseño: si Redis falla, la métrica se descarta y se registra
    | `metrics.failure`, pero el camino caliente (webhook/envío) nunca se bloquea.
    |
    | Se desactiva globalmente poniendo `OBSERVABILITY_METRICS_ENABLED=false`.
    |
    */

    'metrics_enabled' => env('OBSERVABILITY_METRICS_ENABLED', true) !== 'false',

];
