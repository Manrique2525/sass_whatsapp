<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\KnowledgeBase\Services\KnowledgeBaseService;
use App\Domain\KnowledgeBase\Exceptions\KnowledgeBaseDuplicateException;
use App\Domain\KnowledgeBase\Exceptions\KnowledgeBaseNotFoundException;
use App\Domain\Tenants\Exceptions\PermissionDeniedException;
use App\Domain\Tenants\Exceptions\TenantMembershipException;
use App\Domain\Tenants\Exceptions\TenantNotActiveException;
use App\Domain\Tenants\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\KnowledgeBase\KnowledgeBaseIndexRequest;
use App\Http\Requests\KnowledgeBase\StoreKnowledgeBaseRequest;
use App\Http\Requests\KnowledgeBase\UpdateKnowledgeBaseRequest;
use App\Http\Resources\KnowledgeBaseResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Knowledge Bases del tenant activo (FASE 17 U2.1).
 *
 * `{knowledgeBase}` NO usa route-model binding implícito: `SubstituteBindings`
 * corre antes que el middleware `tenant` (que fija TenantContext), así que el
 * parámetro llega como `string` y el servicio lo resuelve filtrando por
 * `tenant_id` autorizado. KB de otro tenant / inexistente → 404.
 */
final class KnowledgeBaseController extends Controller
{
    public function __construct(private readonly KnowledgeBaseService $service) {}

    public function index(KnowledgeBaseIndexRequest $request, Tenant $tenant): JsonResponse
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
            'knowledge_bases' => KnowledgeBaseResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(StoreKnowledgeBaseRequest $request, Tenant $tenant): JsonResponse
    {
        try {
            $knowledgeBase = $this->service->create($request->user(), $tenant, $request->validated());
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (KnowledgeBaseDuplicateException $e) {
            return $this->duplicate($e);
        }

        return response()->json([
            'message' => 'Knowledge base creada.',
            'knowledge_base' => new KnowledgeBaseResource($knowledgeBase),
        ], 201);
    }

    public function show(Request $request, Tenant $tenant, string $knowledgeBase): JsonResponse
    {
        try {
            $knowledgeBase = $this->service->showForUser($request->user(), $tenant, $knowledgeBase);
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (KnowledgeBaseNotFoundException) {
            throw new NotFoundHttpException('Knowledge base no encontrada.');
        }

        return response()->json([
            'knowledge_base' => new KnowledgeBaseResource($knowledgeBase),
        ]);
    }

    public function update(UpdateKnowledgeBaseRequest $request, Tenant $tenant, string $knowledgeBase): JsonResponse
    {
        try {
            $knowledgeBase = $this->service->update(
                $request->user(),
                $tenant,
                $knowledgeBase,
                $request->validated(),
            );
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (KnowledgeBaseDuplicateException $e) {
            return $this->duplicate($e);
        } catch (KnowledgeBaseNotFoundException) {
            throw new NotFoundHttpException('Knowledge base no encontrada.');
        }

        return response()->json([
            'message' => 'Knowledge base actualizada.',
            'knowledge_base' => new KnowledgeBaseResource($knowledgeBase),
        ]);
    }

    public function destroy(Request $request, Tenant $tenant, string $knowledgeBase): JsonResponse
    {
        try {
            $this->service->delete($request->user(), $tenant, $knowledgeBase);
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (KnowledgeBaseNotFoundException) {
            throw new NotFoundHttpException('Knowledge base no encontrada.');
        }

        return response()->json([
            'message' => 'Knowledge base eliminada.',
        ]);
    }

    private function duplicate(KnowledgeBaseDuplicateException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'code' => KnowledgeBaseDuplicateException::ERROR_CODE,
        ], KnowledgeBaseDuplicateException::HTTP_STATUS);
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
