<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Billing\Services\CheckoutService;
use App\Domain\Billing\Exceptions\BillingProviderException;
use App\Domain\Billing\Exceptions\PlanNotFoundException;
use App\Domain\Tenants\Exceptions\PermissionDeniedException;
use App\Domain\Tenants\Exceptions\TenantMembershipException;
use App\Domain\Tenants\Exceptions\TenantNotActiveException;
use App\Domain\Tenants\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\StoreCheckoutRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Checkout session API (FASE 24 U2).
 *
 * POST: create checkout session for a paid plan (billing.manage)
 * POST portal: create customer portal session (billing.manage)
 *
 * Thin controller: all business logic in CheckoutService.
 */
final class CheckoutController extends Controller
{
    public function __construct(private readonly CheckoutService $service) {}

    /**
     * Create a Stripe Checkout Session for the given plan.
     */
    public function store(StoreCheckoutRequest $request, Tenant $tenant): JsonResponse
    {
        try {
            $checkoutData = $this->service->createCheckoutSession(
                $request->user(),
                $tenant,
                $request->validated('plan_id'),
                $request->validated('interval'),
            );
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (PlanNotFoundException $e) {
            throw new NotFoundHttpException($e->getMessage());
        } catch (BillingProviderException $e) {
            return response()->json([
                'message' => 'No se pudo crear la sesión de pago.',
                'code' => 'CHECKOUT_FAILED',
            ], 422);
        }

        return response()->json([
            'checkout_url' => $checkoutData->url,
        ]);
    }

    /**
     * Create a Stripe Customer Portal session.
     */
    public function portal(Request $request, Tenant $tenant): JsonResponse
    {
        try {
            $portalData = $this->service->createPortalSession(
                $request->user(),
                $tenant,
            );
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (BillingProviderException $e) {
            return response()->json([
                'message' => 'No se pudo abrir el portal de facturación.',
                'code' => 'PORTAL_FAILED',
            ], 422);
        }

        return response()->json([
            'portal_url' => $portalData->url,
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
