<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Infrastructure\Health\HealthChecker;
use Illuminate\Http\JsonResponse;

final class HealthController extends Controller
{
    /**
     * Liveness probe: ¿el proceso está vivo?
     * GET /health — 200 si alive, 503 si no.
     * No depende de DB, Redis, ni providers externos.
     */
    public function __invoke(HealthChecker $checker): JsonResponse
    {
        $statuses = $checker->checkLiveness();

        return response()->json(
            [
                'status' => $checker->allOk($statuses) ? 'ok' : 'degraded',
                'mode' => 'liveness',
                'checks' => $statuses,
            ],
            $checker->allOk($statuses) ? 200 : 503,
        );
    }

    /**
     * Readiness probe: ¿puede aceptar trabajo crítico?
     * GET /ready — 200 si listo, 503 si dependencia crítica caída.
     * Verifica DB, Redis, queue backend. Providers externos excluidos.
     */
    public function ready(HealthChecker $checker): JsonResponse
    {
        $readiness = $checker->checkReadiness();
        $healthy = $checker->allOk($readiness);

        $response = [
            'status' => $healthy ? 'ok' : 'degraded',
            'mode' => 'readiness',
            'checks' => $readiness,
        ];

        // Include scheduler heartbeat as informational (does not block readiness)
        $scheduler = $checker->checkSchedulerHeartbeat();
        if ($scheduler !== null) {
            $response['scheduler'] = $scheduler ? 'ok' : 'stale';
        }

        return response()->json($response, $healthy ? 200 : 503);
    }
}
