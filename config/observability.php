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

];
