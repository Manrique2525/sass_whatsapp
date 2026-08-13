<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Infrastructure\Health\HealthChecker;
use Illuminate\Http\JsonResponse;

final class HealthController extends Controller
{
    public function __invoke(HealthChecker $checker): JsonResponse
    {
        $statuses = $checker->checkAll();

        return response()->json(
            [
                'status' => $checker->allOk($statuses) ? 'ok' : 'degraded',
                'components' => $statuses,
            ],
            $checker->allOk($statuses) ? 200 : 503,
        );
    }
}
