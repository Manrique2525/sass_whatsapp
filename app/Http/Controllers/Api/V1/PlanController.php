<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Billing\Services\SubscriptionService;
use App\Domain\Billing\Exceptions\PlanNotFoundException;
use App\Domain\Tenants\Exceptions\PermissionDeniedException;
use App\Domain\Tenants\Exceptions\TenantMembershipException;
use App\Domain\Tenants\Exceptions\TenantNotActiveException;
use App\Domain\Tenants\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Plan catalog API (FASE 23 U3).
 *
 * Plans are global (not tenant-scoped), but endpoints are authenticated
 * and gated by billing.view permission.
 */
final class PlanController extends Controller
{
    public function __construct(private readonly SubscriptionService $service) {}

    /**
     * List all active plans.
     */
    public function index(Request $request, Tenant $tenant): JsonResponse
    {
        try {
            $plans = $this->service->listPlans($request->user(), $tenant);
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        }

        return response()->json([
            'plans' => PlanResource::collection($plans),
        ]);
    }

    /**
     * Show a single plan.
     */
    public function show(Request $request, Tenant $tenant, string $plan): JsonResponse
    {
        try {
            $plan = $this->service->showPlan($request->user(), $tenant, $plan);
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (PlanNotFoundException) {
            throw new NotFoundHttpException('Plan no encontrado.');
        }

        return response()->json([
            'plan' => new PlanResource($plan),
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
