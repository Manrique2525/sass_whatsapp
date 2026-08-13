<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Tenants\Services\SwitchTenant;
use App\Application\Tenants\Services\TenantService;
use App\Domain\Tenants\Exceptions\TenantMembershipException;
use App\Domain\Tenants\Exceptions\TenantNotActiveException;
use App\Domain\Tenants\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\UpdateTenantRequest;
use App\Http\Resources\TenantResource;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class TenantController extends Controller
{
    public function __construct(
        private readonly TenantService $tenantService,
        private readonly SwitchTenant $switchTenant,
    ) {}

    /**
     * Tenants disponibles para el usuario (solo activos) y tenant actual.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'tenants' => TenantResource::collection($this->tenantService->availableForUser($user)),
            'current_tenant_id' => $user->current_tenant_id,
        ]);
    }

    public function show(Request $request, Tenant $tenant): JsonResponse
    {
        try {
            $tenant = $this->tenantService->showForUser($request->user(), $tenant);
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        }

        return response()->json([
            'tenant' => new TenantResource($tenant),
        ]);
    }

    public function update(UpdateTenantRequest $request, Tenant $tenant): JsonResponse
    {
        try {
            $tenant = $this->tenantService->update($request->user(), $tenant, $request->validated());
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        }

        return response()->json([
            'tenant' => new TenantResource($tenant),
        ]);
    }

    public function switch(Request $request, Tenant $tenant): JsonResponse
    {
        try {
            $this->switchTenant->execute($request->user(), $tenant);
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (TenantNotActiveException) {
            return response()->json([
                'message' => 'El tenant no está activo.',
                'code' => 'TENANT_NOT_ACTIVE',
            ], 409);
        } finally {
            TenantContext::clear();
        }

        return response()->json([
            'message' => 'Tenant activo cambiado.',
            'current_tenant' => new TenantResource($tenant),
            'current_tenant_id' => $request->user()->current_tenant_id,
        ]);
    }
}
