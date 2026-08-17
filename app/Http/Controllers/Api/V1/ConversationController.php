<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Conversations\Services\ConversationService;
use App\Domain\Conversations\Exceptions\ConversationAgentNotInTenantException;
use App\Domain\Conversations\Exceptions\ConversationAssignmentConflictException;
use App\Domain\Conversations\Exceptions\ConversationContactNotFoundException;
use App\Domain\Conversations\Exceptions\ConversationInvalidStateException;
use App\Domain\Conversations\Exceptions\ConversationNotFoundException;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Tenants\Exceptions\PermissionDeniedException;
use App\Domain\Tenants\Exceptions\TenantMembershipException;
use App\Domain\Tenants\Exceptions\TenantNotActiveException;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Conversation\AssignConversationRequest;
use App\Http\Requests\Conversation\ClaimConversationRequest;
use App\Http\Requests\Conversation\ConversationIndexRequest;
use App\Http\Requests\Conversation\StoreConversationRequest;
use App\Http\Requests\Conversation\UpdateConversationRequest;
use App\Http\Resources\ConversationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Inbox de conversaciones del tenant activo (FASE 8, ADR-031).
 *
 * Mismas reglas que el resto de recursos de tenant: `{tenant}` debe ser el
 * activo del usuario (si no, 404); `{conversation}` NO usa route-model binding
 * implícito (`SubstituteBindings` corre antes que el middleware `tenant`) y el
 * servicio lo resuelve filtrando por `tenant_id` autorizado. Conversación o
 * contacto de otro tenant / inexistentes → 404.
 */
final class ConversationController extends Controller
{
    public function __construct(private readonly ConversationService $service) {}

    public function index(ConversationIndexRequest $request, Tenant $tenant): JsonResponse
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

        $counts = $this->inboxCounts($request->user(), $tenant);

        return response()->json([
            'conversations' => ConversationResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'counts' => $counts,
        ]);
    }

    /**
     * Contadores de buckets para el inbox (3 COUNTs tenant-scoped).
     *
     * @return array{all: int, mine: int, unassigned: int}
     */
    private function inboxCounts(User $user, Tenant $tenant): array
    {
        $base = Conversation::query()->withoutTenantScope()
            ->where('tenant_id', $tenant->id);

        return [
            'all' => (clone $base)->count(),
            'mine' => (clone $base)->where('agent_id', $user->id)->count(),
            'unassigned' => (clone $base)->whereNull('agent_id')
                ->whereNotNull('handoff_requested_at')
                ->count(),
        ];
    }

    public function store(StoreConversationRequest $request, Tenant $tenant): JsonResponse
    {
        try {
            $conversation = $this->service->create($request->user(), $tenant, $request->validated());
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (ConversationContactNotFoundException) {
            throw new NotFoundHttpException('Contacto no encontrado.');
        }

        return response()->json([
            'message' => 'Conversación creada.',
            'conversation' => new ConversationResource($conversation),
        ], 201);
    }

    public function show(Request $request, Tenant $tenant, string $conversation): JsonResponse
    {
        try {
            $conversation = $this->service->showForUser($request->user(), $tenant, $conversation);
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
            'conversation' => new ConversationResource($conversation),
        ]);
    }

    public function update(UpdateConversationRequest $request, Tenant $tenant, string $conversation): JsonResponse
    {
        try {
            $conversation = $this->service->update(
                $request->user(),
                $tenant,
                $conversation,
                $request->validated(),
            );
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (ConversationNotFoundException) {
            throw new NotFoundHttpException('Conversación no encontrada.');
        } catch (ConversationInvalidStateException $e) {
            return $this->invalidState($e);
        }

        return response()->json([
            'message' => 'Conversación actualizada.',
            'conversation' => new ConversationResource($conversation),
        ]);
    }

    public function assign(AssignConversationRequest $request, Tenant $tenant, string $conversation): JsonResponse
    {
        return $this->changeAgent($request, $tenant, $conversation, transfer: false);
    }

    public function transfer(AssignConversationRequest $request, Tenant $tenant, string $conversation): JsonResponse
    {
        return $this->changeAgent($request, $tenant, $conversation, transfer: true);
    }

    public function claim(ClaimConversationRequest $request, Tenant $tenant, string $conversation): JsonResponse
    {
        try {
            $conversation = $this->service->claim($request->user(), $tenant, $conversation);
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (ConversationNotFoundException) {
            throw new NotFoundHttpException('Conversación no encontrada.');
        } catch (ConversationAgentNotInTenantException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => ConversationAgentNotInTenantException::ERROR_CODE,
            ], ConversationAgentNotInTenantException::HTTP_STATUS);
        } catch (ConversationAssignmentConflictException $e) {
            return $this->assignmentConflict($e);
        } catch (ConversationInvalidStateException $e) {
            return $this->invalidState($e);
        }

        return response()->json([
            'message' => 'Conversación reclamada.',
            'conversation' => new ConversationResource($conversation),
        ]);
    }

    public function close(Request $request, Tenant $tenant, string $conversation): JsonResponse
    {
        try {
            $conversation = $this->service->close($request->user(), $tenant, $conversation);
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (ConversationNotFoundException) {
            throw new NotFoundHttpException('Conversación no encontrada.');
        } catch (ConversationInvalidStateException $e) {
            return $this->invalidState($e);
        }

        return response()->json([
            'message' => 'Conversación cerrada.',
            'conversation' => new ConversationResource($conversation),
        ]);
    }

    public function reopen(Request $request, Tenant $tenant, string $conversation): JsonResponse
    {
        try {
            $conversation = $this->service->reopen($request->user(), $tenant, $conversation);
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
            'message' => 'Conversación reabierta.',
            'conversation' => new ConversationResource($conversation),
        ]);
    }

    public function pauseBot(Request $request, Tenant $tenant, string $conversation): JsonResponse
    {
        return $this->toggleBot($request, $tenant, $conversation, pause: true);
    }

    public function resumeBot(Request $request, Tenant $tenant, string $conversation): JsonResponse
    {
        return $this->toggleBot($request, $tenant, $conversation, pause: false);
    }

    private function changeAgent(
        AssignConversationRequest $request,
        Tenant $tenant,
        string $conversation,
        bool $transfer,
    ): JsonResponse {
        try {
            $conversation = $transfer
                ? $this->service->transfer(
                    $request->user(),
                    $tenant,
                    $conversation,
                    (int) $request->validated('agent_id'),
                )
                : $this->service->assign(
                    $request->user(),
                    $tenant,
                    $conversation,
                    (int) $request->validated('agent_id'),
                );
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (ConversationNotFoundException) {
            throw new NotFoundHttpException('Conversación no encontrada.');
        } catch (ConversationAgentNotInTenantException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => ConversationAgentNotInTenantException::ERROR_CODE,
            ], ConversationAgentNotInTenantException::HTTP_STATUS);
        } catch (ConversationAssignmentConflictException $e) {
            return $this->assignmentConflict($e);
        } catch (ConversationInvalidStateException $e) {
            return $this->invalidState($e);
        }

        return response()->json([
            'message' => $transfer ? 'Conversación transferida.' : 'Conversación asignada.',
            'conversation' => new ConversationResource($conversation),
        ]);
    }

    private function toggleBot(Request $request, Tenant $tenant, string $conversation, bool $pause): JsonResponse
    {
        try {
            $conversation = $pause
                ? $this->service->pauseBot($request->user(), $tenant, $conversation)
                : $this->service->resumeBot($request->user(), $tenant, $conversation);
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (ConversationNotFoundException) {
            throw new NotFoundHttpException('Conversación no encontrada.');
        } catch (ConversationInvalidStateException $e) {
            return $this->invalidState($e);
        }

        return response()->json([
            'message' => $pause ? 'Bot pausado.' : 'Bot reanudado.',
            'conversation' => new ConversationResource($conversation),
        ]);
    }

    private function invalidState(ConversationInvalidStateException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'code' => ConversationInvalidStateException::ERROR_CODE,
        ], ConversationInvalidStateException::HTTP_STATUS);
    }

    private function assignmentConflict(ConversationAssignmentConflictException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'code' => $e->errorCode,
        ], ConversationAssignmentConflictException::HTTP_STATUS);
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
