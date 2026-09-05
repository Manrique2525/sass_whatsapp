<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Visibilidad operator de `failed_jobs` sin imprimir el payload (FASE 31 U6).
 *
 * PII-safe: el payload del job puede contener tenant, phone o contenido. Solo se
 * extrae el `displayName` allowlisted de la envoltura de Laravel; el payload
 * restante nunca se imprime. Para detalle de un job concreto el operador usa el
 * retry/forget del framework (queue:retry / queue:failed).
 */
final class FailedJobsSummaryCommand extends Command
{
    protected $signature = 'queue:failed-summary
        {--json : Salida en JSON}';

    protected $description = 'Resumen agregado de failed_jobs por queue (sin payload ni PII)';

    public function handle(): int
    {
        $total = (int) DB::table('failed_jobs')->count();

        $byQueueAndClass = [];

        DB::table('failed_jobs')
            ->select('queue', 'payload', 'failed_at')
            ->orderByDesc('failed_at')
            ->get()
            ->each(function (object $row) use (&$byQueueAndClass): void {
                $jobClass = $this->extractJobClass((string) $row->payload);
                $key = ((string) $row->queue).'|'.$jobClass;

                if (! isset($byQueueAndClass[$key])) {
                    $byQueueAndClass[$key] = [
                        'queue' => (string) $row->queue,
                        'job_class' => $jobClass,
                        'total' => 0,
                        'last_failed' => (string) $row->failed_at,
                    ];
                }

                $byQueueAndClass[$key]['total']++;
            });

        $byQueue = array_values($byQueueAndClass);

        $daysAgo = (int) config('observability.failed_jobs_retention_days', 30);
        $recent = (int) DB::table('failed_jobs')
            ->where('failed_at', '>=', now()->subDays($daysAgo))
            ->count();

        if ($this->option('json')) {
            $this->line(json_encode([
                'total' => $total,
                'recent_'.$daysAgo => $recent,
                'by_queue' => $byQueue,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $rowsForTable = [];

        foreach ($byQueue as $row) {
            $rowsForTable[] = [
                (string) $row['queue'],
                (string) $row['job_class'],
                (string) $row['total'],
                (string) $row['last_failed'],
            ];
        }

        $this->table(['Queue', 'Job class', 'Total', 'Último fallo'], $rowsForTable);

        $this->info("Total failed_jobs={$total}; fallos recientes ({$daysAgo}d)={$recent}.");

        return self::SUCCESS;
    }

    private function extractJobClass(string $payload): string
    {
        try {
            $decoded = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return 'unknown';
        }

        $jobClass = is_array($decoded) ? ($decoded['displayName'] ?? null) : null;

        if (! is_string($jobClass) || ! preg_match('/\A[A-Za-z_][A-Za-z0-9_\\\\]{0,254}\z/', $jobClass)) {
            return 'unknown';
        }

        return $jobClass;
    }
}
