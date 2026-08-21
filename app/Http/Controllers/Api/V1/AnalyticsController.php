<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Analytics\Services\AnalyticsService;
use App\Domain\Tenants\Exceptions\PermissionDeniedException;
use App\Domain\Tenants\Exceptions\TenantMembershipException;
use App\Domain\Tenants\Exceptions\TenantNotActiveException;
use App\Domain\Tenants\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Analytics\OverviewRequest;
use App\Http\Resources\AnalyticsOverviewResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Analytics del tenant (FASE 21 U3).
 *
 * Thin controller — authorization via AnalyticsService → AuthorizationService.
 * Reads pre-computed analytics_daily rows (U2 materializes, U3 reads).
 */
final class AnalyticsController extends Controller
{
    public function __construct(private readonly AnalyticsService $service) {}

    public function overview(OverviewRequest $request, Tenant $tenant): JsonResponse
    {
        try {
            $overview = $this->service->getOverview(
                $request->user(),
                $tenant,
                $request->validated(),
            );
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        }

        return response()->json([
            'data' => new AnalyticsOverviewResource($overview),
        ]);
    }

    private function forbidden(PermissionDeniedException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'code' => 'PERMISSION_DENIED',
        ], 403);
    }

    private function tenantNotActive(): JsonResponse
    {
        return response()->json([
            'message' => 'El tenant no está activo.',
            'code' => 'TENANT_NOT_ACTIVE',
        ], 409);
    }
}
