<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\WhatsApp\Services\TemplateService;
use App\Domain\Conversations\Exceptions\ConversationInvalidStateException;
use App\Domain\Conversations\Exceptions\ConversationNotFoundException;
use App\Domain\Conversations\Exceptions\ConversationReplyForbiddenException;
use App\Domain\Tenants\Exceptions\PermissionDeniedException;
use App\Domain\Tenants\Exceptions\TenantMembershipException;
use App\Domain\Tenants\Exceptions\TenantNotActiveException;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\WhatsApp\Exceptions\WhatsAppException;
use App\Domain\WhatsApp\Exceptions\WhatsAppNotConnectedException;
use App\Domain\WhatsApp\Exceptions\WhatsAppTemplateNotApprovedException;
use App\Domain\WhatsApp\Exceptions\WhatsAppTemplateNotFoundException;
use App\Domain\WhatsApp\Exceptions\WhatsAppTemplateValidationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Template\SendTemplateRequest;
use App\Http\Resources\MessageResource;
use App\Http\Resources\WhatsAppTemplateResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Catálogo y envío de templates de WhatsApp (FASE 31 U5, ADR-121).
 *
 * Mismas reglas de tenant que el resto de recursos: `{tenant}` debe ser el
 * activo (si no, 404); cross-tenant → 404. `sync` requiere `whatsapp.manage`
 * (owner/admin); `send` usa `messages.send` (owner/admin/agent) para permitir a
 * los agentes enviar templates aprobados desde el inbox.
 */
final class WhatsAppTemplateController extends Controller
{
    public function __construct(private readonly TemplateService $service) {}

    public function index(Request $request, Tenant $tenant): JsonResponse
    {
        try {
            $paginator = $this->service->indexForUser($request->user(), $tenant, $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]));
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        }

        return response()->json([
            'templates' => WhatsAppTemplateResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function sync(Request $request, Tenant $tenant, string $account): JsonResponse
    {
        try {
            $count = $this->service->syncForUser($request->user(), $tenant, $account);
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
            'message' => 'Catálogo de plantillas sincronizado.',
            'synced' => $count,
        ]);
    }

    public function send(SendTemplateRequest $request, Tenant $tenant, string $conversation): JsonResponse
    {
        try {
            $message = $this->service->send(
                $request->user(),
                $tenant,
                $conversation,
                (string) $request->validated('template_id'),
                (array) $request->validated('variables', []),
            );
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (ConversationNotFoundException|WhatsAppTemplateNotFoundException) {
            throw new NotFoundHttpException('Recurso no encontrado.');
        } catch (ConversationInvalidStateException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => ConversationInvalidStateException::ERROR_CODE,
            ], ConversationInvalidStateException::HTTP_STATUS);
        } catch (ConversationReplyForbiddenException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->errorCode,
            ], $e->httpStatus);
        } catch (WhatsAppTemplateNotApprovedException|WhatsAppTemplateValidationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'TEMPLATE_SEND_REJECTED',
            ], $e->status());
        }

        return response()->json([
            'message' => 'Plantilla encolada para envío.',
            'created_message' => new MessageResource($message),
        ], 201);
    }

    private function whatsappError(WhatsAppException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'code' => $e->errorCode()->value,
        ], $e->status());
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
