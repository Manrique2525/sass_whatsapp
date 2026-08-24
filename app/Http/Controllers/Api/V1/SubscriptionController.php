<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Billing\Services\SubscriptionService;
use App\Domain\Billing\Exceptions\PlanNotFoundException;
use App\Domain\Billing\Exceptions\SubscriptionNotFoundException;
use App\Domain\Tenants\Exceptions\PermissionDeniedException;
use App\Domain\Tenants\Exceptions\TenantMembershipException;
use App\Domain\Tenants\Exceptions\TenantNotActiveException;
use App\Domain\Tenants\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\StoreSubscriptionRequest;
use App\Http\Requests\Billing\UpdateSubscriptionRequest;
use App\Http\Resources\SubscriptionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Subscription management API (FASE 23 U3).
 *
 * GET: view current subscription (billing.view)
 * POST: assign plan (billing.manage)
 * PATCH: change plan (billing.manage)
 * DELETE: cancel subscription (billing.manage)
 *
 * `{subscription}` is NOT used in routes — the active subscription
 * is resolved server-side per tenant.
 */
final class SubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionService $service) {}

    /**
     * Get the current active subscription for the tenant.
     */
    public function index(Request $request, Tenant $tenant): JsonResponse
    {
        try {
            $subscription = $this->service->currentSubscription($request->user(), $tenant);
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        }

        if ($subscription === null) {
            return response()->json([
                'subscription' => null,
            ]);
        }

        return response()->json([
            'subscription' => new SubscriptionResource($subscription),
        ]);
    }

    /**
     * Assign a plan to the tenant (create or replace subscription).
     */
    public function store(StoreSubscriptionRequest $request, Tenant $tenant): JsonResponse
    {
        try {
            $subscription = $this->service->assignPlan(
                $request->user(),
                $tenant,
                $request->validated('plan_id'),
            );
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
            'message' => 'Plan asignado.',
            'subscription' => new SubscriptionResource($subscription),
        ], 201);
    }

    /**
     * Change the plan for the current subscription.
     */
    public function update(UpdateSubscriptionRequest $request, Tenant $tenant): JsonResponse
    {
        try {
            $subscription = $this->service->changePlan(
                $request->user(),
                $tenant,
                $request->validated('plan_id'),
            );
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (PlanNotFoundException) {
            throw new NotFoundHttpException('Plan no encontrado.');
        } catch (SubscriptionNotFoundException) {
            throw new NotFoundHttpException('No hay suscripción activa.');
        }

        return response()->json([
            'message' => 'Plan actualizado.',
            'subscription' => new SubscriptionResource($subscription),
        ]);
    }

    /**
     * Cancel the active subscription.
     */
    public function destroy(Request $request, Tenant $tenant): JsonResponse
    {
        try {
            $this->service->cancel($request->user(), $tenant);
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (SubscriptionNotFoundException) {
            throw new NotFoundHttpException('No hay suscripción activa.');
        }

        return response()->json([
            'message' => 'Suscripción cancelada.',
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
