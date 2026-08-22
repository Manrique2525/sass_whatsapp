<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Notifications\Services\NotificationPreferenceService;
use App\Domain\Tenants\Exceptions\PermissionDeniedException;
use App\Domain\Tenants\Exceptions\TenantMembershipException;
use App\Domain\Tenants\Exceptions\TenantNotActiveException;
use App\Domain\Tenants\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\NotificationPreferenceRequest;
use App\Http\Resources\NotificationPreferenceResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Preferencias de notificación del usuario autenticado (FASE 22 U4).
 *
 * GET/PATCH para leer/actualizar `email_notifications_enabled`.
 * Solo modifica la preferencia del usuario autenticado en el tenant.
 */
final class NotificationPreferenceController extends Controller
{
    public function __construct(
        private readonly NotificationPreferenceService $service,
    ) {}

    public function show(Request $request, Tenant $tenant): JsonResponse
    {
        try {
            $enabled = $this->service->get($request->user(), $tenant);
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        }

        return response()->json(
            new NotificationPreferenceResource($enabled),
        );
    }

    public function update(NotificationPreferenceRequest $request, Tenant $tenant): JsonResponse
    {
        try {
            $this->service->update(
                $request->user(),
                $tenant,
                $request->validated('email_notifications_enabled'),
            );
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        }

        return response()->json([
            'message' => 'Preferencia actualizada.',
            'email_notifications_enabled' => $request->validated('email_notifications_enabled'),
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
