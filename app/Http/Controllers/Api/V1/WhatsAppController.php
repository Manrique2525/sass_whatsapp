<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\WhatsApp\Services\WhatsAppConnectionService;
use App\Domain\Tenants\Exceptions\PermissionDeniedException;
use App\Domain\Tenants\Exceptions\TenantMembershipException;
use App\Domain\Tenants\Exceptions\TenantNotActiveException;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\WhatsApp\Exceptions\WhatsAppAuthFailedException;
use App\Domain\WhatsApp\Exceptions\WhatsAppException;
use App\Domain\WhatsApp\Exceptions\WhatsAppMessageFailedException;
use App\Domain\WhatsApp\Exceptions\WhatsAppNotConnectedException;
use App\Domain\WhatsApp\Exceptions\WhatsAppPhoneNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Requests\WhatsApp\ConnectWhatsAppRequest;
use App\Http\Resources\WhatsAppAccountResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Estado y conexión de la cuenta de WhatsApp del tenant activo.
 *
 * Mismas reglas que el resto de recursos de tenant: `{tenant}` debe ser el
 * activo del usuario (si no, 404); permisos `whatsapp.view` (lectura) y
 * `whatsapp.manage` (owner/admin). El token NUNCA se expone (hidden en el
 * modelo y no se incluye en el resource).
 */
final class WhatsAppController extends Controller
{
    public function __construct(private readonly WhatsAppConnectionService $service) {}

    public function show(Request $request, Tenant $tenant): JsonResponse
    {
        try {
            $account = $this->service->showForUser($request->user(), $tenant);
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        }

        return response()->json([
            'whatsapp_account' => $account !== null
                ? new WhatsAppAccountResource($account->loadMissing('phoneNumbers'))
                : null,
        ]);
    }

    public function connect(ConnectWhatsAppRequest $request, Tenant $tenant): JsonResponse
    {
        try {
            $result = $this->service->connect($request->user(), $tenant, $request->validated());
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (WhatsAppAuthFailedException|WhatsAppPhoneNotFoundException|WhatsAppMessageFailedException $e) {
            return $this->whatsappError($e);
        }

        return response()->json([
            'message' => 'Cuenta de WhatsApp conectada.',
            'whatsapp_account' => new WhatsAppAccountResource($result['account']),
            'webhook_subscribed' => $result['webhook_subscribed'],
        ]);
    }

    public function disconnect(Request $request, Tenant $tenant): JsonResponse
    {
        try {
            $account = $this->service->disconnect($request->user(), $tenant);
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (WhatsAppNotConnectedException $e) {
            return $this->whatsappError($e);
        }

        return response()->json([
            'message' => 'Cuenta de WhatsApp desconectada.',
            'whatsapp_account' => new WhatsAppAccountResource($account),
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

    private function whatsappError(WhatsAppException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'code' => $e->errorCode()->value,
        ], $e->status());
    }
}
