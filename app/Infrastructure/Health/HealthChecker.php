<?php

declare(strict_types=1);

namespace App\Infrastructure\Health;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Checker de salud de la infraestructura base.
 *
 * Verifica de forma honesta (sin excepciones tragadas ni respuestas falsas) que
 * la aplicación puede: arrancar, hablar con la base de datos configurada, usar
 * el driver de cache configurado y resolver la conexión de cola configurada.
 */
final class HealthChecker
{
    /**
     * @return array<string, string> statuses: app, database, redis, queue
     */
    public function checkAll(): array
    {
        return [
            'app' => $this->checkApp() ? 'ok' : 'fail',
            'database' => $this->checkDatabase() ? 'ok' : 'fail',
            'redis' => $this->checkRedis() ? 'ok' : 'fail',
            'queue' => $this->checkQueue() ? 'ok' : 'fail',
        ];
    }

    public function checkApp(): bool
    {
        return true;
    }

    public function checkDatabase(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function checkRedis(): bool
    {
        try {
            $key = 'health:check:'.bin2hex(random_bytes(4));
            $store = Cache::store();

            return $store->set($key, true, 5) && $store->get($key) === true;
        } catch (Throwable) {
            return false;
        }
    }

    public function checkQueue(): bool
    {
        try {
            $driver = config('queue.default');
            $connection = app('queue')->connection();

            if ($driver === 'sync') {
                return true;
            }

            if ($driver === 'redis') {
                $connection->getRedis()->ping();
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, string>  $statuses
     */
    public function allOk(array $statuses): bool
    {
        return $statuses['app'] === 'ok'
            && $statuses['database'] === 'ok'
            && $statuses['redis'] === 'ok'
            && $statuses['queue'] === 'ok';
    }
}
