<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\KnowledgeBase\Services\DocumentService;
use App\Domain\KnowledgeBase\Exceptions\DocumentNotFoundException;
use App\Domain\KnowledgeBase\Exceptions\KnowledgeBaseNotFoundException;
use App\Domain\Tenants\Exceptions\PermissionDeniedException;
use App\Domain\Tenants\Exceptions\TenantMembershipException;
use App\Domain\Tenants\Exceptions\TenantNotActiveException;
use App\Domain\Tenants\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\KnowledgeBase\DocumentIndexRequest;
use App\Http\Resources\DocumentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Documentos de una Knowledge Base del tenant activo (FASE 17 U2.1).
 *
 * U2.1 implementa únicamente list, show y delete. El upload real (POST
 * multipart) está diferido a U2.2 porque requiere Storage.
 *
 * `{document}` NO usa route-model binding: el servicio lo resuelve filtrando
 * por `tenant_id` autorizado. Documento de otro tenant / inexistente → 404.
 */
final class DocumentController extends Controller
{
    public function __construct(private readonly DocumentService $service) {}

    public function index(DocumentIndexRequest $request, Tenant $tenant, string $knowledgeBase): JsonResponse
    {
        try {
            $paginator = $this->service->index(
                $request->user(),
                $tenant,
                $knowledgeBase,
                $request->validated(),
            );
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (KnowledgeBaseNotFoundException) {
            throw new NotFoundHttpException('Knowledge base no encontrada.');
        }

        return response()->json([
            'documents' => DocumentResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(Request $request, Tenant $tenant, string $knowledgeBase, string $document): JsonResponse
    {
        try {
            $document = $this->service->showForUser($request->user(), $tenant, $knowledgeBase, $document);
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (KnowledgeBaseNotFoundException) {
            throw new NotFoundHttpException('Knowledge base no encontrada.');
        } catch (DocumentNotFoundException) {
            throw new NotFoundHttpException('Documento no encontrado.');
        }

        return response()->json([
            'document' => new DocumentResource($document),
        ]);
    }

    public function destroy(Request $request, Tenant $tenant, string $knowledgeBase, string $document): JsonResponse
    {
        try {
            $this->service->delete($request->user(), $tenant, $knowledgeBase, $document);
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (KnowledgeBaseNotFoundException) {
            throw new NotFoundHttpException('Knowledge base no encontrada.');
        } catch (DocumentNotFoundException) {
            throw new NotFoundHttpException('Documento no encontrado.');
        }

        return response()->json([
            'message' => 'Documento eliminado.',
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
