<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Infrastructure\Health\HealthChecker;
use Illuminate\Console\Command;

final class HealthCheck extends Command
{
    protected $signature = 'health:check {--json : Salida en JSON}';

    protected $description = 'Verifica el estado de app, base de datos, cache/redis y cola';

    public function handle(HealthChecker $checker): int
    {
        $statuses = $checker->checkAll();

        if ($this->option('json')) {
            $this->line(json_encode([
                'status' => $checker->allOk($statuses) ? 'ok' : 'degraded',
                'components' => $statuses,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $checker->allOk($statuses) ? self::SUCCESS : self::FAILURE;
        }

        $this->table(['Componente', 'Estado'], array_map(
            fn (string $component, string $state) => [$component, $state],
            array_keys($statuses),
            array_values($statuses),
        ));

        return $checker->allOk($statuses) ? self::SUCCESS : self::FAILURE;
    }
}
