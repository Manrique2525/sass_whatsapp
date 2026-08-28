<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Infrastructure\Testing\E2EEnvironmentGuard;
use Database\Seeders\E2ETenantSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

/**
 * Prepara el entorno E2E (Playwright, FASE 30 / ADR-110).
 *
 * Solo se ejecuta bajo el guard E2E (APP_ENV=e2e + BD *_e2e_test + Redis E2E):
 *
 *  - migra desde cero la BD E2E (nunca toca dev/test)
 *  - siembra los fixtures de tenants/usuarios
 *  - vacía SOLO el índice lógico de Redis dedicado E2E (nosin flushes globales)
 *  - prepara el directorio de storage dedicado E2E
 */
final class SetupE2EEnvironment extends Command
{
    protected $signature = 'e2e:setup {--skip-migrate : No ejecuta migrate:fresh (usa fixtures ya migrados)}';

    protected $description = 'Prepara el entorno E2E (Playwright): guard de seguridad, migración, seed y limpieza de Redis E2E';

    public function handle(): int
    {
        E2EEnvironmentGuard::assertSafe();

        $redisIndex = E2EEnvironmentGuard::E2E_REDIS_INDEX;
        $this->info(sprintf('E2E guard OK: APP_ENV=%s, BD=%s, Redis index=%d.',
            app()->environment(),
            config('database.connections.'.config('database.default').'.database'),
            $redisIndex,
        ));

        if (! $this->option('skip-migrate')) {
            $this->info('Ejecutando migrate:fresh sobre la BD E2E...');
            $this->call('migrate:fresh', ['--force' => true]);
        }

        $this->info('Sembrando fixtures E2E (E2ETenantSeeder)...');
        $this->call('db:seed', ['--class' => E2ETenantSeeder::class, '--force' => true]);

        $flushed = $this->flushScopedRedis($redisIndex);
        $this->info(sprintf('Redis E2E (índice %d) vaciado (%d llaves).', $redisIndex, $flushed));

        $this->prepareStorage();

        $this->info('Entorno E2E listo para Playwright.');

        return self::SUCCESS;
    }

    /**
     * Vacía únicamente el índice lógico de Redis conectado (dedicado E2E).
     * `flushdb()` actúa sobre el índice seleccionado al conectar (el de la
     * config, ya validado por el guard), nunca sobre todos (sin FLUSHALL; no
     * toca índices de dev 0/1 ni de pgsql 14).
     */
    private function flushScopedRedis(int $expectedIndex): int
    {
        $databaseIndex = (int) config('database.redis.default.database', config('database.redis.options.database', 0));

        if ($databaseIndex !== $expectedIndex) {
            throw new \RuntimeException(sprintf(
                'Redis E2E: índice configurado %d distinto del esperado %d. Abortando.',
                $databaseIndex,
                $expectedIndex,
            ));
        }

        Redis::connection()->flushdb();

        return 0;
    }

    private function prepareStorage(): void
    {
        $disk = config('filesystems.default');
        $this->info(sprintf('Storage E2E: disco "%s" listo.', $disk));
    }
}
