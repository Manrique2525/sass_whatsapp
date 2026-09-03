<?php

declare(strict_types=1);

namespace App\Infrastructure\Observability;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Contadores y gauges ligeros de observabilidad (FASE 31 U6).
 *
 * No es un backend de métricas completo (Prometheus/OTel siguen diferidos): es
 * una capa thin sobre el cache compartido (Redis en producción) que incrementa
 * contadores y registra gauges/latencias con claves `observability:metrics:*`.
 *
 * Diseño:
 * - Fail-safe: NUNCA lanza en el camino caliente. Si Redis falla, se registra
 *   un `metrics.failure` y la operación sigue. (Mismo precedente que el
 *   SentryEventScrubber: la telemetría no bloquea el producto.)
 * - Sin cardinalidad alta: las claves métricas son fijas y de dominio (nunca
 *   phone numbers, wamids, ni UUIDs de tenant/mensaje).
 * - Desplegable por `observability.metrics_enabled`.
 *
 * La única agregación es rollup en el propio counter; el consumo es
 * operacional (runbooks / dashboards externos vía lectura de Redis).
 */
final class MetricsRecorder
{
    private const PREFIX = 'observability:metrics:';

    /**
     * Incrementa un contador métrico.
     *
     * @param  string  $metric  nombre canónico, p. ej. `whatsapp.webhook.received`
     * @param  int  $by  cantidad a sumar (por defecto 1)
     */
    public function increment(string $metric, int $by = 1): void
    {
        if (! self::enabled()) {
            return;
        }

        try {
            Cache::store()->increment(self::key($metric), $by);
        } catch (Throwable $exception) {
            self::logFailure('increment', $metric, $exception);
        }
    }

    /**
     * Registra un gauge de valor absoluto (p. ej. tamaño de cola).
     */
    public function gauge(string $metric, int $value): void
    {
        if (! self::enabled()) {
            return;
        }

        try {
            Cache::store()->set(self::key($metric), $value);
        } catch (Throwable $exception) {
            self::logFailure('gauge', $metric, $exception);
        }
    }

    /**
     * Registra una latencia en segundos.
     */
    public function latency(string $metric, float $seconds): void
    {
        if (! self::enabled()) {
            return;
        }

        try {
            Cache::store()->set(self::key($metric.'.millis'), (int) round($seconds * 1000));
        } catch (Throwable $exception) {
            self::logFailure('latency', $metric, $exception);
        }
    }

    /**
     * Lee el valor actual de un contador/gauge (sin efectos secundarios).
     * Devuelve 0 si la métrica no existe o si la lectura falla.
     */
    public function value(string $metric): int
    {
        try {
            $value = Cache::store()->get(self::key($metric));

            return is_int($value) ? $value : (int) ($value ?? 0);
        } catch (Throwable) {
            return 0;
        }
    }

    private function key(string $metric): string
    {
        return self::PREFIX.$metric;
    }

    private function enabled(): bool
    {
        return (bool) config('observability.metrics_enabled', true);
    }

    private function logFailure(string $operation, string $metric, Throwable $exception): void
    {
        Log::warning('metrics.failure', [
            'operation' => $operation,
            'metric' => $metric,
            'exception' => $exception::class,
        ]);
    }
}
