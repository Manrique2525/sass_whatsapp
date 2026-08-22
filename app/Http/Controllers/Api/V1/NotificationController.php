<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Notifications\Services\NotificationService;
use App\Domain\Notifications\Exceptions\NotificationNotFoundException;
use App\Domain\Tenants\Exceptions\PermissionDeniedException;
use App\Domain\Tenants\Exceptions\TenantMembershipException;
use App\Domain\Tenants\Exceptions\TenantNotActiveException;
use App\Domain\Tenants\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\NotificationIndexRequest;
use App\Http\Resources\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Notificaciones in-app del usuario autenticado (FASE 22 U3).
 *
 * `{notification}` NO usa route-model binding: el servicio lo resuelve filtrando
 * por `tenant_id` + `user_id` autorizado. Notificación de otro usuario/tenant /
 * inexistente → 404.
 */
final class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $service) {}

    public function index(NotificationIndexRequest $request, Tenant $tenant): JsonResponse
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
            'notifications' => NotificationResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'counts' => [
                'unread' => $this->service->unreadCount($request->user(), $tenant),
            ],
        ]);
    }

    public function markRead(Request $request, Tenant $tenant, string $notification): JsonResponse
    {
        try {
            $notification = $this->service->markAsRead($request->user(), $tenant, $notification);
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (NotificationNotFoundException) {
            throw new NotFoundHttpException('Notificación no encontrada.');
        }

        return response()->json([
            'notification' => new NotificationResource($notification),
        ]);
    }

    public function markAllRead(Request $request, Tenant $tenant): JsonResponse
    {
        try {
            $count = $this->service->markAllAsRead($request->user(), $tenant);
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        }

        return response()->json([
            'message' => 'Notificaciones marcadas como leídas.',
            'updated' => $count,
            'counts' => [
                'unread' => $this->service->unreadCount($request->user(), $tenant),
            ],
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
