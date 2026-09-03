<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Messages\Services\MediaStorageService;
use App\Domain\Tenants\Exceptions\PermissionDeniedException;
use App\Domain\Tenants\Exceptions\TenantMembershipException;
use App\Domain\Tenants\Exceptions\TenantNotActiveException;
use App\Domain\Tenants\Models\Tenant;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Descarga de assets de media (FASE 31 U5, ADR-121).
 *
 * Siempre tenant-scoped: un media de OTRO tenant (o inexistente, o no
 * `downloaded`) responde 404 (no se revela la existencia). El filename se sirve
 * con Content-Disposition seguro; nunca se exponen `storage_disk`/`storage_path`.
 */
final class MessageMediaController extends Controller
{
    public function __construct(private readonly MediaStorageService $mediaService) {}

    public function download(Request $request, Tenant $tenant, string $media): Response
    {
        try {
            $info = $this->mediaService->deliveryInfoForUser($request->user(), $tenant, $media);
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        }

        if ($info === null) {
            throw new NotFoundHttpException('Media no encontrado.');
        }

        $disk = Storage::disk($info['disk']);

        if (! $disk->exists($info['path'])) {
            throw new NotFoundHttpException('Media no encontrado.');
        }

        return $disk->download($info['path'], $info['filename']);
    }

    private function forbidden(PermissionDeniedException $e): Response
    {
        return response()->json([
            'message' => $e->getMessage(),
            'code' => 'PERMISSION_DENIED',
        ], 403);
    }
}
