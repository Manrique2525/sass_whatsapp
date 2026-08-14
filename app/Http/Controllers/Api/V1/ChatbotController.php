<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Flows\Services\FlowService;
use App\Domain\Flows\Exceptions\ChatbotHasPublishedFlowsException;
use App\Domain\Flows\Exceptions\ChatbotNotFoundException;
use App\Domain\Tenants\Exceptions\PermissionDeniedException;
use App\Domain\Tenants\Exceptions\TenantMembershipException;
use App\Domain\Tenants\Exceptions\TenantNotActiveException;
use App\Domain\Tenants\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chatbot\ChatbotIndexRequest;
use App\Http\Requests\Chatbot\StoreChatbotRequest;
use App\Http\Requests\Chatbot\UpdateChatbotRequest;
use App\Http\Resources\ChatbotResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Chatbots del tenant activo (FASE 11, ADR-035).
 *
 * Mismas reglas que el resto de recursos de tenant: `{tenant}` debe ser el
 * activo del usuario (si no, 404); `{chatbot}` NO usa route-model binding
 * implícito y el servicio lo resuelve filtrando por `tenant_id` autorizado.
 * Chatbot de otro tenant / inexistente → 404.
 */
final class ChatbotController extends Controller
{
    public function __construct(private readonly FlowService $service) {}

    public function index(ChatbotIndexRequest $request, Tenant $tenant): JsonResponse
    {
        try {
            $paginator = $this->service->indexChatbots($request->user(), $tenant, $request->validated());
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        }

        return response()->json([
            'chatbots' => ChatbotResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(StoreChatbotRequest $request, Tenant $tenant): JsonResponse
    {
        try {
            $chatbot = $this->service->createChatbot($request->user(), $tenant, $request->validated());
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        }

        return response()->json([
            'message' => 'Chatbot creado.',
            'chatbot' => new ChatbotResource($chatbot),
        ], 201);
    }

    public function show(Request $request, Tenant $tenant, string $chatbot): JsonResponse
    {
        try {
            $chatbot = $this->service->showChatbot($request->user(), $tenant, $chatbot);
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
            'chatbot' => new ChatbotResource($chatbot),
        ]);
    }

    public function update(UpdateChatbotRequest $request, Tenant $tenant, string $chatbot): JsonResponse
    {
        try {
            $chatbot = $this->service->updateChatbot($request->user(), $tenant, $chatbot, $request->validated());
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
            'message' => 'Chatbot actualizado.',
            'chatbot' => new ChatbotResource($chatbot),
        ]);
    }

    public function destroy(Request $request, Tenant $tenant, string $chatbot): JsonResponse
    {
        try {
            $this->service->deleteChatbot($request->user(), $tenant, $chatbot);
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (ChatbotNotFoundException) {
            throw new NotFoundHttpException('Chatbot no encontrado.');
        } catch (ChatbotHasPublishedFlowsException $e) {
            return $this->error($e);
        }

        return response()->json([
            'message' => 'Chatbot eliminado.',
        ]);
    }

    private function error(ChatbotHasPublishedFlowsException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'code' => ChatbotHasPublishedFlowsException::ERROR_CODE,
        ], ChatbotHasPublishedFlowsException::HTTP_STATUS);
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
