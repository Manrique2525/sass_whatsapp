<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\WhatsApp\Services\WhatsAppPhoneHealthService;
use App\Domain\Tenants\Exceptions\PermissionDeniedException;
use App\Domain\Tenants\Exceptions\TenantMembershipException;
use App\Domain\Tenants\Exceptions\TenantNotActiveException;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\WhatsApp\Exceptions\WhatsAppException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Endpoint de operaciones de salud de números de WhatsApp (FASE 31 U6).
 *
 * Requiere `whatsapp.manage` (owner/admin). Scopeado a `{tenant}`; un no-miembro
 * o miembro de otro tenant recibe 404. NO desconecta números: solo refresca
 * campos informativos y reporta el estado del provider.
 */
final class WhatsAppPhoneHealthController extends Controller
{
    public function __construct(private readonly WhatsAppPhoneHealthService $service) {}

    public function check(Request $request, Tenant $tenant): JsonResponse
    {
        try {
            $result = $this->service->check($request->user(), $tenant);
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (WhatsAppException $e) {
            return $this->whatsappError($e);
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

    private function whatsappError(WhatsAppException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'code' => $e->errorCode()->value,
        ], $e->status());
    }
}
