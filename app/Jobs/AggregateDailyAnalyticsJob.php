<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Analytics\Services\AggregationService;
use App\Domain\Tenants\Models\Tenant;
use App\Jobs\Concerns\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Materializes daily analytics aggregates for a single tenant+date.
 *
 * Idempotent: re-dispatching for same (tenant, date) is a no-op via ShouldBeUnique.
 * AggregationService itself is also idempotent (UPSERT replaces).
 */
final class AggregateDailyAnalyticsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TenantAwareJob;

    public int $timeout = 300;

    public function __construct(
        string $tenantId,
        public readonly string $date,
    ) {
        $this->tenantId = $tenantId;
        $this->afterCommit = true;
    }

    public function uniqueId(): string
    {
        return "analytics:aggregate:{$this->tenantId}:{$this->date}";
    }

    public function uniqueFor(): int
    {
        return 300;
    }

    public function tries(): int
    {
        return 3;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 60, 120];
    }

    protected function executeInTenantContext(): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        $lock = Cache::lock(
            "lock:tenant:{$this->tenantId}:analytics:aggregate:{$this->date}",
            $this->timeout + 30,
        );

        try {
            $lock->block(seconds: 10);
        } catch (LockTimeoutException) {
            $this->release(5);

            return;
        }

        try {
            /** @var AggregationService $service */
            $service = app(AggregationService::class);
            $service->aggregateForDate($tenant, $this->date);
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        // No PII logged. Silent fail — job will retry up to `tries()` before reaching here.
    }
}
