<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Business\Services\BusinessProfileService;
use App\Domain\Tenants\Exceptions\PermissionDeniedException;
use App\Domain\Tenants\Exceptions\TenantMembershipException;
use App\Domain\Tenants\Exceptions\TenantNotActiveException;
use App\Domain\Tenants\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Business\UpdateBusinessProfileRequest;
use App\Http\Resources\BusinessProfileResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Perfil de negocio del tenant activo del usuario.
 *
 * El `{tenant}` de la ruta debe ser SIEMPRE el tenant activo del usuario; si
 * no, 404 (se oculta la existencia, ADR-010/023). El `tenant_id` nunca se
 * recibe del frontend: lo resuelve TenantContext + autorización.
 */
final class BusinessProfileController extends Controller
{
    public function __construct(private readonly BusinessProfileService $service) {}

    public function show(Request $request, Tenant $tenant): JsonResponse
    {
        try {
            $profile = $this->service->showForUser($request->user(), $tenant);
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        }

        return response()->json([
            'business_profile' => new BusinessProfileResource($profile),
        ]);
    }

    public function update(UpdateBusinessProfileRequest $request, Tenant $tenant): JsonResponse
    {
        try {
            $profile = $this->service->update(
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
            'message' => 'Perfil de negocio actualizado.',
            'business_profile' => new BusinessProfileResource($profile),
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
