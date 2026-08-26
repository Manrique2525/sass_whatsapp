<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Prune old failed_jobs records in batches to avoid table locks.
 *
 * Default retention: 30 days. Configurable via --days.
 * Uses batched deletes (500 rows/iteration) to stay safe on large tables.
 */
final class PruneFailedJobsCommand extends Command
{
    protected $signature = 'queue:prune-failed
        {--days= : Retention period in days (default: 30)}
        {--batch=500 : Batch size for deletes}
        {--dry-run : Show what would be deleted without deleting}';

    protected $description = 'Prune failed_jobs records older than the retention period';

    private const DEFAULT_RETENTION_DAYS = 30;

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: self::DEFAULT_RETENTION_DAYS);
        $batchSize = (int) $this->option('batch');
        $dryRun = $this->option('dry-run');

        $cutoff = now()->subDays($days);
        $cutoffFormatted = $cutoff->toDateTimeString();

        if ($dryRun) {
            $count = DB::table('failed_jobs')
                ->where('failed_at', '<', $cutoff)
                ->count();

            $this->info("DRY RUN: {$count} records older than {$cutoffFormatted} would be deleted.");

            return self::SUCCESS;
        }

        $totalDeleted = 0;

        while (true) {
            $deleted = DB::table('failed_jobs')
                ->where('failed_at', '<', $cutoff)
                ->limit($batchSize)
                ->delete();

            $totalDeleted += $deleted;

            if ($deleted < $batchSize) {
                break;
            }
        }

        $this->info("Pruned {$totalDeleted} failed_jobs records older than {$cutoffFormatted}.");

        return self::SUCCESS;
    }
}
