<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Tenants\Models\Tenant;
use App\Jobs\AggregateDailyAnalyticsJob;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Dispatches AggregateDailyAnalyticsJob for each tenant.
 *
 * For each tenant, computes "yesterday" in the tenant's timezone
 * so each tenant gets the correct analytics day regardless of UTC offset.
 *
 * Usage:
 *   php artisan analytics:aggregate-daily
 *   php artisan analytics:aggregate-daily --date=2026-08-20
 */
final class AggregateDailyAnalyticsCommand extends Command
{
    protected $signature = 'analytics:aggregate-daily {--date= : Analytics date (YYYY-MM-DD). Default: yesterday in UTC.}';

    protected $description = 'Dispatch daily analytics aggregation for all tenants';

    public function handle(): int
    {
        $dateOption = $this->option('date');

        $tenants = Tenant::query()->select('id', 'timezone')->get();

        if ($tenants->isEmpty()) {
            $this->info('No tenants found.');

            return self::SUCCESS;
        }

        $dispatched = 0;

        foreach ($tenants as $tenant) {
            $date = $dateOption ?? CarbonImmutable::yesterday($tenant->timezone ?? 'UTC')->toDateString();

            AggregateDailyAnalyticsJob::dispatch($tenant->id, $date)
                ->onQueue('analytics');

            $dispatched++;
        }

        $this->info("Dispatched {$dispatched} analytics aggregation jobs.");

        return self::SUCCESS;
    }
}
