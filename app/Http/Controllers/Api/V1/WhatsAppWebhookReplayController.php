<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\WhatsApp\Services\WhatsAppWebhookReplayService;
use App\Domain\Tenants\Exceptions\PermissionDeniedException;
use App\Domain\Tenants\Exceptions\TenantMembershipException;
use App\Domain\Tenants\Exceptions\TenantNotActiveException;
use App\Domain\Tenants\Models\Tenant;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Replay operator de eventos de webhook `failed`/`received` del tenant activo
 * (FASE 31 U6).
 *
 * Endpoint de operaciones: requiere `whatsapp.manage` (owner/admin). Está
 * scopeado a `{tenant}` como el resto de recursos de tenant; un no-miembro o un
 * miembro de otro tenant recibe 404. Nunca lista ni replaya eventos de otros
 * tenants.
 */
final class WhatsAppWebhookReplayController extends Controller
{
    public function __construct(private readonly WhatsAppWebhookReplayService $service) {}

    public function count(Request $request, Tenant $tenant): JsonResponse
    {
        try {
            $summary = $this->service->count($request->user(), $tenant);
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        }

        return response()->json($summary);
    }

    public function replayFailed(Request $request, Tenant $tenant): JsonResponse
    {
        $limit = (int) ($request->input('limit') ?? 100);

        try {
            $result = $this->service->replayFailed($request->user(), $tenant, $limit);
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        }

        return response()->json($result);
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
