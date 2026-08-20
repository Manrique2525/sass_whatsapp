<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Leads\Services\LeadService;
use App\Domain\Leads\Exceptions\LeadDuplicateException;
use App\Domain\Leads\Exceptions\LeadNotFoundException;
use App\Domain\Tenants\Exceptions\PermissionDeniedException;
use App\Domain\Tenants\Exceptions\TenantMembershipException;
use App\Domain\Tenants\Exceptions\TenantNotActiveException;
use App\Domain\Tenants\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Leads\LeadIndexRequest;
use App\Http\Requests\Leads\StoreLeadRequest;
use App\Http\Requests\Leads\UpdateLeadRequest;
use App\Http\Resources\LeadResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Leads del tenant activo (FASE 19 U2).
 *
 * `{lead}` NO usa route-model binding: el servicio lo resuelve filtrando por
 * `tenant_id` autorizado. Lead de otro tenant / inexistente → 404.
 */
final class LeadController extends Controller
{
    public function __construct(private readonly LeadService $service) {}

    public function index(LeadIndexRequest $request, Tenant $tenant): JsonResponse
    {
        try {
            $paginator = $this->service->index($request->user(), $tenant, $request->validated());
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        }

        return response()->json([
            'leads' => LeadResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(StoreLeadRequest $request, Tenant $tenant): JsonResponse
    {
        try {
            $lead = $this->service->create($request->user(), $tenant, $request->validated());
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (LeadDuplicateException $e) {
            return $this->duplicate($e);
        }

        return response()->json([
            'message' => 'Lead creado.',
            'lead' => new LeadResource($lead),
        ], 201);
    }

    public function show(Request $request, Tenant $tenant, string $lead): JsonResponse
    {
        try {
            $lead = $this->service->showForUser($request->user(), $tenant, $lead);
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (LeadNotFoundException) {
            throw new NotFoundHttpException('Lead no encontrado.');
        }

        return response()->json([
            'lead' => new LeadResource($lead),
        ]);
    }

    public function update(UpdateLeadRequest $request, Tenant $tenant, string $lead): JsonResponse
    {
        try {
            $lead = $this->service->update(
                $request->user(),
                $tenant,
                $lead,
                $request->validated(),
            );
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (LeadDuplicateException $e) {
            return $this->duplicate($e);
        } catch (LeadNotFoundException) {
            throw new NotFoundHttpException('Lead no encontrado.');
        } catch (\DomainException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'LEAD_INVALID_TRANSITION',
            ], 422);
        }

        return response()->json([
            'message' => 'Lead actualizado.',
            'lead' => new LeadResource($lead),
        ]);
    }

    public function destroy(Request $request, Tenant $tenant, string $lead): JsonResponse
    {
        try {
            $this->service->delete($request->user(), $tenant, $lead);
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (LeadNotFoundException) {
            throw new NotFoundHttpException('Lead no encontrado.');
        }

        return response()->json([
            'message' => 'Lead eliminado.',
        ]);
    }

    private function duplicate(LeadDuplicateException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'code' => LeadDuplicateException::ERROR_CODE,
        ], LeadDuplicateException::HTTP_STATUS);
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
