<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Messages\Services\MessageService;
use App\Domain\Conversations\Exceptions\ConversationNotFoundException;
use App\Domain\Tenants\Exceptions\PermissionDeniedException;
use App\Domain\Tenants\Exceptions\TenantMembershipException;
use App\Domain\Tenants\Exceptions\TenantNotActiveException;
use App\Domain\Tenants\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Message\MessageIndexRequest;
use App\Http\Requests\Message\StoreMessageRequest;
use App\Http\Resources\MessageResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Historial y envío de mensajes de una conversación (FASE 10, ADR-033).
 *
 * Mismas reglas que el resto de recursos de tenant (FASE 8/ADR-031):
 * `{tenant}` debe ser el activo (si no, 404); la conversación se resuelve en
 * el servicio filtrando por `tenant_id` autorizado. Conversación de otro
 * tenant / inexistente → 404. `tenant_id` nunca viene del frontend.
 */
final class MessagesController extends Controller
{
    public function __construct(private readonly MessageService $service) {}

    public function index(MessageIndexRequest $request, Tenant $tenant, string $conversation): JsonResponse
    {
        try {
            $paginator = $this->service->indexForUser($request->user(), $tenant, $conversation, $request->validated());
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (ConversationNotFoundException) {
            throw new NotFoundHttpException('Conversación no encontrada.');
        }

        return response()->json([
            'messages' => MessageResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(StoreMessageRequest $request, Tenant $tenant, string $conversation): JsonResponse
    {
        try {
            $message = $this->service->send(
                $request->user(),
                $tenant,
                $conversation,
                (string) $request->validated('body'),
            );
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (ConversationNotFoundException) {
            throw new NotFoundHttpException('Conversación no encontrada.');
        }

        return response()->json([
            'message' => 'Mensaje encolado para envío.',
            'created_message' => new MessageResource($message),
        ], 201);
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
