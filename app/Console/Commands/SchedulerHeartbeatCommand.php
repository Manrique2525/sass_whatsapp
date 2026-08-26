<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Writes a heartbeat timestamp to cache so the readiness probe
 * can verify the scheduler loop is alive.
 *
 * Registered in routes/console.php to run every minute.
 * The cache key is simple integer (timestamp) — no PII, no tenant data.
 */
final class SchedulerHeartbeatCommand extends Command
{
    protected $signature = 'scheduler:heartbeat';

    protected $description = 'Write scheduler heartbeat timestamp to cache';

    private const CACHE_KEY = 'observability:scheduler:last_heartbeat';

    public function handle(): int
    {
        Cache::store()->set(self::CACHE_KEY, time(), 300);

        return self::SUCCESS;
    }
}
