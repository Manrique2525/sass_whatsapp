<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Flows\Services\FlowExecutionService;
use App\Domain\Flows\Exceptions\FlowExecutionInvalidStateException;
use App\Domain\Flows\Exceptions\FlowExecutionNotFoundException;
use App\Domain\Tenants\Exceptions\PermissionDeniedException;
use App\Domain\Tenants\Exceptions\TenantMembershipException;
use App\Domain\Tenants\Exceptions\TenantNotActiveException;
use App\Domain\Tenants\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Flow\ExecutionIndexRequest;
use App\Http\Resources\FlowExecutionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Ejecuciones de flujos del tenant activo (FASE 11, ADR-037).
 *
 * Lectura con `flows.view` (todos los roles), mutación (pause/resume/cancel)
 * con `flows.manage` (owner/admin). `{execution}` se resuelve como `string`
 * filtrando por `tenant_id` autorizado (404 oculta la existencia cross-tenant).
 */
final class FlowExecutionController extends Controller
{
    public function __construct(private readonly FlowExecutionService $service) {}

    public function index(ExecutionIndexRequest $request, Tenant $tenant): JsonResponse
    {
        try {
            $paginator = $this->service->indexExecutions($request->user(), $tenant, $request->validated());
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        }

        return response()->json([
            'executions' => FlowExecutionResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(Request $request, Tenant $tenant, string $execution): JsonResponse
    {
        try {
            $execution = $this->service->showExecution($request->user(), $tenant, $execution);
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (FlowExecutionNotFoundException) {
            throw new NotFoundHttpException('Ejecución no encontrada.');
        }

        return response()->json([
            'execution' => new FlowExecutionResource($execution),
        ]);
    }

    public function pause(Request $request, Tenant $tenant, string $execution): JsonResponse
    {
        return $this->toggle($request, $tenant, $execution, pause: true);
    }

    public function resume(Request $request, Tenant $tenant, string $execution): JsonResponse
    {
        return $this->toggle($request, $tenant, $execution, pause: false);
    }

    public function cancel(Request $request, Tenant $tenant, string $execution): JsonResponse
    {
        try {
            $execution = $this->service->cancel($request->user(), $tenant, $execution);
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (FlowExecutionNotFoundException) {
            throw new NotFoundHttpException('Ejecución no encontrada.');
        } catch (FlowExecutionInvalidStateException $e) {
            return $this->invalidState($e);
        }

        return response()->json([
            'message' => 'Ejecución cancelada.',
            'execution' => new FlowExecutionResource($execution),
        ]);
    }

    private function toggle(Request $request, Tenant $tenant, string $execution, bool $pause): JsonResponse
    {
        try {
            $execution = $pause
                ? $this->service->pause($request->user(), $tenant, $execution)
                : $this->service->resume($request->user(), $tenant, $execution);
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (FlowExecutionNotFoundException) {
            throw new NotFoundHttpException('Ejecución no encontrada.');
        } catch (FlowExecutionInvalidStateException $e) {
            return $this->invalidState($e);
        }

        return response()->json([
            'message' => $pause ? 'Bot pausado.' : 'Bot reanudado.',
            'execution' => new FlowExecutionResource($execution),
        ]);
    }

    private function invalidState(FlowExecutionInvalidStateException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'code' => FlowExecutionInvalidStateException::ERROR_CODE,
        ], FlowExecutionInvalidStateException::HTTP_STATUS);
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
