<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Billing\Services\UsageTrackingService;
use App\Application\Users\Services\AuthorizationService;
use App\Domain\Billing\Enums\UsageCategory;
use App\Domain\Billing\Exceptions\SubscriptionNotFoundException;
use App\Domain\Tenants\Exceptions\PermissionDeniedException;
use App\Domain\Tenants\Exceptions\TenantMembershipException;
use App\Domain\Tenants\Exceptions\TenantNotActiveException;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\TenantPermission;
use App\Http\Controllers\Controller;
use App\Http\Resources\UsageRecordResource;
use App\Http\Resources\UsageSummaryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Usage API (FASE 23 U3).
 *
 * Reuses UsageTrackingService from U2.
 * No reimplemented SUM/period logic.
 */
final class UsageController extends Controller
{
    public function __construct(
        private readonly UsageTrackingService $usageService,
        private readonly AuthorizationService $authorization,
    ) {}

    /**
     * Get usage summary for the current billing period.
     */
    public function index(Request $request, Tenant $tenant): JsonResponse
    {
        try {
            $this->authorization->authorize(
                $request->user(),
                TenantPermission::ViewBilling,
                $tenant,
            );

            $summary = $this->usageService->currentPeriodSummary($tenant);
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (SubscriptionNotFoundException) {
            return response()->json([
                'message' => 'No hay suscripción activa.',
                'code' => 'SUBSCRIPTION_NOT_FOUND',
            ], 404);
        }

        return response()->json([
            'usage' => new UsageSummaryResource($summary),
        ]);
    }

    /**
     * Get paginated usage history.
     *
     * Supported filters: category, from, to, per_page.
     */
    public function history(Request $request, Tenant $tenant): JsonResponse
    {
        try {
            $this->authorization->authorize(
                $request->user(),
                TenantPermission::ViewBilling,
                $tenant,
            );
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        }

        $filters = array_filter([
            'category' => $request->input('category') !== null
                ? UsageCategory::tryFrom($request->input('category'))
                : null,
            'from' => $request->input('from'),
            'to' => $request->input('to'),
            'per_page' => $request->input('per_page'),
        ], static fn ($v) => $v !== null);

        $paginator = $this->usageService->history($tenant, $filters);

        return response()->json([
            'usage_records' => UsageRecordResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
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
