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
 * Liveness: ¿el proceso Laravel está vivo? (sin dependencias externas)
 * Readiness: ¿puede aceptar trabajo crítico? (DB, Redis, queue backend)
 *
 * Providers externos (Meta, OpenAI, Stripe) NO se verifican en readiness —
 * su caída no impide que la app procese trabajo local.
 */
final class HealthChecker
{
    private const SCHEDULER_HEARTBEAT_KEY = 'observability:scheduler:last_heartbeat';

    /**
     * @return array<string, string>
     */
    public function checkAll(): array
    {
        return array_merge(
            $this->checkLiveness(),
            $this->checkReadiness(),
        );
    }

    /**
     * Liveness: solo verifica que el proceso PHP/Laravel está vivo.
     * Barato, rápido, sin dependencias externas.
     *
     * @return array{app: string}
     */
    public function checkLiveness(): array
    {
        return [
            'app' => $this->checkApp() ? 'ok' : 'fail',
        ];
    }

    /**
     * Readiness: verifica dependencias críticas locales.
     * DB down = 503, Redis down = 503 (si es queue backend).
     * Providers externos NUNCA bloquean readiness.
     *
     * @return array<string, string>
     */
    public function checkReadiness(): array
    {
        return [
            'database' => $this->checkDatabase() ? 'ok' : 'fail',
            'redis' => $this->checkRedis() ? 'ok' : 'fail',
            'queue' => $this->checkQueue() ? 'ok' : 'fail',
        ];
    }

    public function checkApp(): bool
    {
        try {
            return config('app.name') !== null;
        } catch (Throwable) {
            return false;
        }
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

            if ($driver === 'sync') {
                return true;
            }

            if ($driver === 'redis') {
                $connection = app('queue')->connection();
                $connection->getRedis()->ping();
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Optional: check scheduler heartbeat freshness.
     * Returns null if no heartbeat recorded yet (startup grace).
     * Returns true if heartbeat is fresh, false if stale.
     */
    public function checkSchedulerHeartbeat(): ?bool
    {
        try {
            $last = Cache::store()->get(self::SCHEDULER_HEARTBEAT_KEY);

            if ($last === null) {
                return null;
            }

            $maxAge = (int) config('observability.scheduler_heartbeat_max_age_seconds', 120);

            return (time() - (int) $last) < $maxAge;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, string>  $statuses
     */
    public function allOk(array $statuses): bool
    {
        foreach ($statuses as $status) {
            if ($status !== 'ok') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, string>  $statuses
     */
    public function anyFail(array $statuses): bool
    {
        return ! $this->allOk($statuses);
    }
}
