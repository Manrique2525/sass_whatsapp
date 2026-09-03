<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Visibilidad operator de `failed_jobs` SIN tocar el payload (FASE 31 U6).
 *
 * PII-safe: el payload del job puede contener tenant, phone o contenido, así que
 * este comando jamás lo lee ni imprime. Agrega por queue y expone solo el
 * conteo por día, la última fila y el total. Para detalle de un job concreto el
 * operador usa el retry/forget del framework (queue:retry / queue:failed).
 */
final class FailedJobsSummaryCommand extends Command
{
    protected $signature = 'queue:failed-summary
        {--json : Salida en JSON}';

    protected $description = 'Resumen agregado de failed_jobs por queue (sin payload ni PII)';

    public function handle(): int
    {
        $total = (int) DB::table('failed_jobs')->count();

        $byQueue = DB::table('failed_jobs')
            ->select('queue', DB::raw('count(*) as total'), DB::raw('max(failed_at) as last_failed'))
            ->groupBy('queue')
            ->orderByDesc('last_failed')
            ->get()
            ->all();

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
                (string) $row['total'],
                (string) ($row['last_failed'] ?? ''),
            ];
        }

        $this->table(['Queue', 'Total', 'Último fallo'], $rowsForTable);

        $this->info("Total failed_jobs={$total}; fallos recientes (${daysAgo}d)={$recent}.");

        return self::SUCCESS;
    }
}
