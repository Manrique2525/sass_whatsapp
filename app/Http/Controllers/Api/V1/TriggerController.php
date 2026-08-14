<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Flows\Services\FlowService;
use App\Domain\Flows\Exceptions\FlowNotFoundException;
use App\Domain\Flows\Exceptions\FlowPublishedException;
use App\Domain\Flows\Exceptions\TriggerNotFoundException;
use App\Domain\Tenants\Exceptions\PermissionDeniedException;
use App\Domain\Tenants\Exceptions\TenantMembershipException;
use App\Domain\Tenants\Exceptions\TenantNotActiveException;
use App\Domain\Tenants\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Flow\StoreTriggerRequest;
use App\Http\Requests\Flow\UpdateTriggerRequest;
use App\Http\Resources\TriggerResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Triggers de un flujo del tenant activo (FASE 11).
 *
 * Solo editables en flujos no publicados (`409 FLOW_PUBLISHED`). `{flow}` y
 * `{trigger}` se resuelven como `string` filtrando por `tenant_id` autorizado.
 */
final class TriggerController extends Controller
{
    public function __construct(private readonly FlowService $service) {}

    public function index(Request $request, Tenant $tenant, string $flow): JsonResponse
    {
        try {
            $triggers = $this->service->indexTriggers($request->user(), $tenant, $flow);
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
            'triggers' => TriggerResource::collection($triggers),
        ]);
    }

    public function store(StoreTriggerRequest $request, Tenant $tenant, string $flow): JsonResponse
    {
        try {
            $trigger = $this->service->createTrigger($request->user(), $tenant, $flow, $request->validated());
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (FlowNotFoundException) {
            throw new NotFoundHttpException('Flujo no encontrado.');
        } catch (FlowPublishedException $e) {
            return $this->published($e);
        }

        return response()->json([
            'message' => 'Trigger creado.',
            'trigger' => new TriggerResource($trigger),
        ], 201);
    }

    public function update(UpdateTriggerRequest $request, Tenant $tenant, string $flow, string $trigger): JsonResponse
    {
        try {
            $trigger = $this->service->updateTrigger($request->user(), $tenant, $flow, $trigger, $request->validated());
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (FlowNotFoundException) {
            throw new NotFoundHttpException('Flujo no encontrado.');
        } catch (TriggerNotFoundException) {
            throw new NotFoundHttpException('Trigger no encontrado.');
        } catch (FlowPublishedException $e) {
            return $this->published($e);
        }

        return response()->json([
            'message' => 'Trigger actualizado.',
            'trigger' => new TriggerResource($trigger),
        ]);
    }

    public function destroy(Request $request, Tenant $tenant, string $flow, string $trigger): JsonResponse
    {
        try {
            $this->service->deleteTrigger($request->user(), $tenant, $flow, $trigger);
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (FlowNotFoundException) {
            throw new NotFoundHttpException('Flujo no encontrado.');
        } catch (TriggerNotFoundException) {
            throw new NotFoundHttpException('Trigger no encontrado.');
        } catch (FlowPublishedException $e) {
            return $this->published($e);
        }

        return response()->json([
            'message' => 'Trigger eliminado.',
        ]);
    }

    private function published(FlowPublishedException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'code' => FlowPublishedException::ERROR_CODE,
        ], FlowPublishedException::HTTP_STATUS);
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
