<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Infrastructure\Testing\E2EEnvironmentGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use RuntimeException;

/** Verifica que el stack E2E no deja trabajos pendientes tras la suite. */
final class AssertE2EQueueClean extends Command
{
    protected $signature = 'e2e:assert-queue-clean {--report-only : Reporta el estado sin cambiar el resultado del proceso}';

    protected $description = 'Verifica que las colas E2E default, knowledge y analytics están limpias';

    public function handle(): int
    {
        E2EEnvironmentGuard::assertSafe();

        $failed = (int) DB::table('failed_jobs')->count();
        $pending = 0;
        $reserved = 0;
        $delayed = 0;

        foreach (['default', 'knowledge', 'analytics'] as $queue) {
            $pending += (int) Redis::connection()->llen('queues:'.$queue);
            $reserved += (int) Redis::connection()->zcard('queues:'.$queue.':reserved');
            $delayed += (int) Redis::connection()->zcard('queues:'.$queue.':delayed');
        }

        $this->line("failed_jobs={$failed}");
        $this->line("pending={$pending}");
        $this->line("reserved={$reserved}");
        $this->line("delayed={$delayed}");

        $dirty = $failed !== 0 || $pending !== 0 || $reserved !== 0 || $delayed !== 0;

        if ($dirty && ! $this->option('report-only')) {
            throw new RuntimeException('E2E queue guard: quedaron trabajos después de la suite.');
        }

        return self::SUCCESS;
    }
}
