<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Flows\Services\FlowService;
use App\Domain\Flows\Exceptions\ChatbotNotFoundException;
use App\Domain\Flows\Exceptions\FlowAlreadyPublishedException;
use App\Domain\Flows\Exceptions\FlowConflictException;
use App\Domain\Flows\Exceptions\FlowInvalidException;
use App\Domain\Flows\Exceptions\FlowInvalidStateException;
use App\Domain\Flows\Exceptions\FlowNotFoundException;
use App\Domain\Flows\Exceptions\FlowPublishedException;
use App\Domain\Tenants\Exceptions\PermissionDeniedException;
use App\Domain\Tenants\Exceptions\TenantMembershipException;
use App\Domain\Tenants\Exceptions\TenantNotActiveException;
use App\Domain\Tenants\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Flow\FlowIndexRequest;
use App\Http\Requests\Flow\ReplaceDraftRequest;
use App\Http\Requests\Flow\StoreFlowRequest;
use App\Http\Requests\Flow\UpdateFlowRequest;
use App\Http\Resources\FlowResource;
use App\Http\Resources\VariableDefinitionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Flujos de un chatbot del tenant activo (FASE 11, ADR-034/035).
 *
 * `{chatbot}` y `{flow}` se resuelven como `string` (sin route-model binding
 * implícito) y el servicio filtra SIEMPRE por `tenant_id` autorizado (404
 * oculta la existencia cross-tenant). Publicar valida el grafo (`FlowValidator`);
 * un flujo publicado no se edita (`409 FLOW_PUBLISHED`).
 */
final class FlowController extends Controller
{
    public function __construct(private readonly FlowService $service) {}

    public function index(FlowIndexRequest $request, Tenant $tenant, string $chatbot): JsonResponse
    {
        try {
            $paginator = $this->service->indexFlows($request->user(), $tenant, $chatbot, $request->validated());
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (ChatbotNotFoundException) {
            throw new NotFoundHttpException('Chatbot no encontrado.');
        }

        return response()->json([
            'flows' => FlowResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(StoreFlowRequest $request, Tenant $tenant, string $chatbot): JsonResponse
    {
        try {
            $flow = $this->service->createFlow($request->user(), $tenant, $chatbot, $request->validated());
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (ChatbotNotFoundException) {
            throw new NotFoundHttpException('Chatbot no encontrado.');
        }

        return response()->json([
            'message' => 'Flujo creado.',
            'flow' => new FlowResource($flow),
        ], 201);
    }

    public function show(Request $request, Tenant $tenant, string $flow): JsonResponse
    {
        try {
            $flow = $this->service->showFlow($request->user(), $tenant, $flow);
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (FlowNotFoundException) {
            throw new NotFoundHttpException('Flujo no encontrado.');
        }

        return response()->json([
            'flow' => new FlowResource($flow),
        ]);
    }

    public function update(UpdateFlowRequest $request, Tenant $tenant, string $flow): JsonResponse
    {
        try {
            $flow = $this->service->updateFlow($request->user(), $tenant, $flow, $request->validated());
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (FlowNotFoundException) {
            throw new NotFoundHttpException('Flujo no encontrado.');
        } catch (FlowPublishedException $e) {
            return $this->error($e);
        }

        return response()->json([
            'message' => 'Flujo actualizado.',
            'flow' => new FlowResource($flow),
        ]);
    }

    public function replaceDraft(ReplaceDraftRequest $request, Tenant $tenant, string $flow): JsonResponse
    {
        try {
            $flow = $this->service->replaceDraft(
                $request->user(),
                $tenant,
                $flow,
                $request->validated('nodes'),
                $request->validated('connections'),
                $request->validated('base_updated_at'),
            );
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (FlowNotFoundException) {
            throw new NotFoundHttpException('Flujo no encontrado.');
        } catch (FlowPublishedException $e) {
            return $this->error($e);
        } catch (FlowConflictException $e) {
            return $this->error($e);
        } catch (FlowInvalidException $e) {
            return $this->invalid($e);
        }

        return response()->json([
            'message' => 'Borrador guardado.',
            'flow' => new FlowResource($flow),
        ]);
    }

    public function validate(Request $request, Tenant $tenant, string $flow): JsonResponse
    {
        try {
            $result = $this->service->validateFlow($request->user(), $tenant, $flow);
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (FlowNotFoundException) {
            throw new NotFoundHttpException('Flujo no encontrado.');
        }

        return response()->json($result);
    }

    public function publish(Request $request, Tenant $tenant, string $flow): JsonResponse
    {
        try {
            $flow = $this->service->publish($request->user(), $tenant, $flow);
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (FlowNotFoundException) {
            throw new NotFoundHttpException('Flujo no encontrado.');
        } catch (FlowAlreadyPublishedException $e) {
            return $this->error($e);
        } catch (FlowInvalidException $e) {
            return $this->invalid($e);
        }

        return response()->json([
            'message' => 'Flujo publicado.',
            'flow' => new FlowResource($flow),
        ]);
    }

    public function deactivate(Request $request, Tenant $tenant, string $flow): JsonResponse
    {
        try {
            $flow = $this->service->deactivate($request->user(), $tenant, $flow);
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (FlowNotFoundException) {
            throw new NotFoundHttpException('Flujo no encontrado.');
        } catch (FlowInvalidStateException $e) {
            return $this->invalidState($e);
        }

        return response()->json([
            'message' => 'Flujo desactivado.',
            'flow' => new FlowResource($flow),
        ]);
    }

    public function destroy(Request $request, Tenant $tenant, string $flow): JsonResponse
    {
        try {
            $this->service->deleteFlow($request->user(), $tenant, $flow);
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (FlowNotFoundException) {
            throw new NotFoundHttpException('Flujo no encontrado.');
        } catch (FlowPublishedException $e) {
            return $this->error($e);
        }

        return response()->json([
            'message' => 'Flujo eliminado.',
        ]);
    }

    /**
     * Catálogo de variables del flujo (FASE 13, UNIDAD 3): solo lectura
     * (`flows.view`). Devuelve DEFINICIONES derivadas, nunca valores runtime.
     */
    public function variables(Request $request, Tenant $tenant, string $flow): JsonResponse
    {
        try {
            $variables = $this->service->flowVariables($request->user(), $tenant, $flow);
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (FlowNotFoundException) {
            throw new NotFoundHttpException('Flujo no encontrado.');
        }

        return response()->json([
            'variables' => VariableDefinitionResource::collection($variables),
        ]);
    }

    private function error(FlowPublishedException|FlowAlreadyPublishedException|FlowConflictException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'code' => $e::ERROR_CODE,
        ], $e::HTTP_STATUS);
    }

    private function invalid(FlowInvalidException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'code' => FlowInvalidException::ERROR_CODE,
            'errors' => $e->errors(),
        ], FlowInvalidException::HTTP_STATUS);
    }

    private function invalidState(FlowInvalidStateException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'code' => FlowInvalidStateException::ERROR_CODE,
        ], FlowInvalidStateException::HTTP_STATUS);
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
