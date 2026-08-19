<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Faq\Services\FaqService;
use App\Domain\Faq\Exceptions\FaqDuplicateException;
use App\Domain\Faq\Exceptions\FaqInvalidQuestionException;
use App\Domain\Faq\Exceptions\FaqNotFoundException;
use App\Domain\Tenants\Exceptions\PermissionDeniedException;
use App\Domain\Tenants\Exceptions\TenantMembershipException;
use App\Domain\Tenants\Exceptions\TenantNotActiveException;
use App\Domain\Tenants\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Faq\FaqIndexRequest;
use App\Http\Requests\Faq\StoreFaqRequest;
use App\Http\Requests\Faq\UpdateFaqRequest;
use App\Http\Resources\FaqResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * FAQs del tenant activo (FASE 18 U3).
 *
 * `{faq}` NO usa route-model binding: el servicio lo resuelve filtrando por
 * `tenant_id` autorizado. FAQ de otro tenant / inexistente → 404.
 */
final class FaqController extends Controller
{
    public function __construct(private readonly FaqService $service) {}

    public function index(FaqIndexRequest $request, Tenant $tenant): JsonResponse
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
            'faqs' => FaqResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(StoreFaqRequest $request, Tenant $tenant): JsonResponse
    {
        try {
            $faq = $this->service->create($request->user(), $tenant, $request->validated());
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (FaqDuplicateException $e) {
            return $this->duplicate($e);
        } catch (FaqInvalidQuestionException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => FaqInvalidQuestionException::ERROR_CODE,
            ], FaqInvalidQuestionException::HTTP_STATUS);
        }

        return response()->json([
            'message' => 'FAQ creada.',
            'faq' => new FaqResource($faq),
        ], 201);
    }

    public function show(Request $request, Tenant $tenant, string $faq): JsonResponse
    {
        try {
            $faq = $this->service->showForUser($request->user(), $tenant, $faq);
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (FaqNotFoundException) {
            throw new NotFoundHttpException('FAQ no encontrada.');
        }

        return response()->json([
            'faq' => new FaqResource($faq),
        ]);
    }

    public function update(UpdateFaqRequest $request, Tenant $tenant, string $faq): JsonResponse
    {
        try {
            $faq = $this->service->update(
                $request->user(),
                $tenant,
                $faq,
                $request->validated(),
            );
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (FaqDuplicateException $e) {
            return $this->duplicate($e);
        } catch (FaqInvalidQuestionException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => FaqInvalidQuestionException::ERROR_CODE,
            ], FaqInvalidQuestionException::HTTP_STATUS);
        } catch (FaqNotFoundException) {
            throw new NotFoundHttpException('FAQ no encontrada.');
        }

        return response()->json([
            'message' => 'FAQ actualizada.',
            'faq' => new FaqResource($faq),
        ]);
    }

    public function destroy(Request $request, Tenant $tenant, string $faq): JsonResponse
    {
        try {
            $this->service->delete($request->user(), $tenant, $faq);
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (FaqNotFoundException) {
            throw new NotFoundHttpException('FAQ no encontrada.');
        }

        return response()->json([
            'message' => 'FAQ eliminada.',
        ]);
    }

    private function duplicate(FaqDuplicateException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'code' => FaqDuplicateException::ERROR_CODE,
        ], FaqDuplicateException::HTTP_STATUS);
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
