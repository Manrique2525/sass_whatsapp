<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Contacts\Services\ContactService;
use App\Domain\Contacts\Exceptions\ContactDuplicateException;
use App\Domain\Contacts\Exceptions\ContactNotFoundException;
use App\Domain\Tenants\Exceptions\PermissionDeniedException;
use App\Domain\Tenants\Exceptions\TenantMembershipException;
use App\Domain\Tenants\Exceptions\TenantNotActiveException;
use App\Domain\Tenants\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contact\ContactIndexRequest;
use App\Http\Requests\Contact\StoreContactRequest;
use App\Http\Requests\Contact\UpdateContactRequest;
use App\Http\Resources\ContactResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * CRM de contactos del tenant activo del usuario (FASE 7, ADR-030).
 *
 * `{contact}` NO usa route-model binding implícito: `SubstituteBindings` corre
 * antes que el middleware `tenant` (que fija TenantContext), así que el
 * parámetro llega como `string` y el servicio lo resuelve filtrando por
 * `tenant_id` autorizado. Contacto de otro tenant / inexistente → 404.
 */
final class ContactController extends Controller
{
    public function __construct(private readonly ContactService $service) {}

    public function index(ContactIndexRequest $request, Tenant $tenant): JsonResponse
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
            'contacts' => ContactResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(StoreContactRequest $request, Tenant $tenant): JsonResponse
    {
        try {
            $contact = $this->service->create($request->user(), $tenant, $request->validated());
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (ContactDuplicateException $e) {
            return $this->duplicate($e);
        }

        return response()->json([
            'message' => 'Contacto creado.',
            'contact' => new ContactResource($contact),
        ], 201);
    }

    public function show(Request $request, Tenant $tenant, string $contact): JsonResponse
    {
        try {
            $contact = $this->service->showForUser($request->user(), $tenant, $contact);
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (ContactNotFoundException) {
            throw new NotFoundHttpException('Contacto no encontrado.');
        }

        return response()->json([
            'contact' => new ContactResource($contact),
        ]);
    }

    public function update(UpdateContactRequest $request, Tenant $tenant, string $contact): JsonResponse
    {
        try {
            $contact = $this->service->update(
                $request->user(),
                $tenant,
                $contact,
                $request->validated(),
            );
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (ContactDuplicateException $e) {
            return $this->duplicate($e);
        } catch (ContactNotFoundException) {
            throw new NotFoundHttpException('Contacto no encontrado.');
        }

        return response()->json([
            'message' => 'Contacto actualizado.',
            'contact' => new ContactResource($contact),
        ]);
    }

    public function destroy(Request $request, Tenant $tenant, string $contact): JsonResponse
    {
        try {
            $this->service->delete($request->user(), $tenant, $contact);
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (ContactNotFoundException) {
            throw new NotFoundHttpException('Contacto no encontrado.');
        }

        return response()->json([
            'message' => 'Contacto eliminado.',
        ]);
    }

    private function duplicate(ContactDuplicateException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'code' => ContactDuplicateException::ERROR_CODE,
        ], ContactDuplicateException::HTTP_STATUS);
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
