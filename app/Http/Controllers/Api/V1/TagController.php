<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Contacts\Services\TagService;
use App\Domain\Contacts\Exceptions\TagDuplicateException;
use App\Domain\Contacts\Exceptions\TagNotFoundException;
use App\Domain\Tenants\Exceptions\PermissionDeniedException;
use App\Domain\Tenants\Exceptions\TenantMembershipException;
use App\Domain\Tenants\Exceptions\TenantNotActiveException;
use App\Domain\Tenants\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tags\AssignContactTagsRequest;
use App\Http\Requests\Tags\StoreTagRequest;
use App\Http\Requests\Tags\TagIndexRequest;
use App\Http\Requests\Tags\UpdateTagRequest;
use App\Http\Resources\ContactResource;
use App\Http\Resources\TagResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tags del tenant activo (FASE 20 U2).
 *
 * `{tag}` NO usa route-model binding: el servicio lo resuelve filtrando por
 * `tenant_id` autorizado. Tag de otro tenant / inexistente → 404.
 */
final class TagController extends Controller
{
    public function __construct(private readonly TagService $service) {}

    public function index(TagIndexRequest $request, Tenant $tenant): JsonResponse
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
            'tags' => TagResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(StoreTagRequest $request, Tenant $tenant): JsonResponse
    {
        try {
            $tag = $this->service->create($request->user(), $tenant, $request->validated());
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (TagDuplicateException $e) {
            return $this->duplicate($e);
        }

        return response()->json([
            'message' => 'Tag creado.',
            'tag' => new TagResource($tag),
        ], 201);
    }

    public function show(Request $request, Tenant $tenant, string $tag): JsonResponse
    {
        try {
            $tag = $this->service->show($request->user(), $tenant, $tag);
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (TagNotFoundException) {
            throw new NotFoundHttpException('Tag no encontrado.');
        }

        return response()->json([
            'tag' => new TagResource($tag),
        ]);
    }

    public function update(UpdateTagRequest $request, Tenant $tenant, string $tag): JsonResponse
    {
        try {
            $tag = $this->service->update(
                $request->user(),
                $tenant,
                $tag,
                $request->validated(),
            );
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (TagDuplicateException $e) {
            return $this->duplicate($e);
        } catch (TagNotFoundException) {
            throw new NotFoundHttpException('Tag no encontrado.');
        }

        return response()->json([
            'message' => 'Tag actualizado.',
            'tag' => new TagResource($tag),
        ]);
    }

    public function destroy(Request $request, Tenant $tenant, string $tag): JsonResponse
    {
        try {
            $this->service->delete($request->user(), $tenant, $tag);
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (TagNotFoundException) {
            throw new NotFoundHttpException('Tag no encontrado.');
        }

        return response()->json([
            'message' => 'Tag eliminado.',
        ]);
    }

    // ── U3: Tag assignment/removal ─────────────────────────────

    public function assignTags(AssignContactTagsRequest $request, Tenant $tenant, string $contact): JsonResponse
    {
        try {
            $contactModel = $this->service->assignTagsToContact(
                $request->user(),
                $tenant,
                $contact,
                $request->validated('tag_ids'),
            );
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (\DomainException $e) {
            throw new NotFoundHttpException($e->getMessage());
        }

        return response()->json([
            'message' => 'Tags asignados.',
            'contact' => new ContactResource($contactModel),
        ]);
    }

    public function removeTag(Request $request, Tenant $tenant, string $contact, string $tag): JsonResponse
    {
        try {
            $this->service->removeTagFromContact(
                $request->user(),
                $tenant,
                $contact,
                $tag,
            );
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (TagNotFoundException) {
            throw new NotFoundHttpException('Tag no encontrado.');
        } catch (\DomainException $e) {
            throw new NotFoundHttpException($e->getMessage());
        }

        return response()->json([
            'message' => 'Tag removido.',
        ]);
    }

    private function duplicate(TagDuplicateException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'code' => TagDuplicateException::ERROR_CODE,
        ], TagDuplicateException::HTTP_STATUS);
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
