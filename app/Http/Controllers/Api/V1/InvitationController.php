<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Users\Services\InvitationService;
use App\Domain\Users\Exceptions\InvitationAlreadyAcceptedException;
use App\Domain\Users\Exceptions\InvitationEmailMismatchException;
use App\Domain\Users\Exceptions\InvitationExpiredException;
use App\Domain\Users\Exceptions\InvitationNotFoundException;
use App\Domain\Users\Exceptions\InvitationRevokedException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Endpoints públicos de invitación.
 *
 * `show` es accesible sin autenticación: el enlace ES la credencial (token
 * plano) y solo expone datos de la invitación (tenant, rol, email). `accept`
 * requiere usuario autenticado cuyo email coincida con el de la invitación.
 */
final class InvitationController extends Controller
{
    public function __construct(private readonly InvitationService $invitationService) {}

    public function show(Request $request, string $token): JsonResponse
    {
        try {
            $invitation = $this->invitationService->findForToken($token);
        } catch (InvitationNotFoundException) {
            throw new NotFoundHttpException('Invitación no encontrada.');
        } catch (InvitationAlreadyAcceptedException|InvitationRevokedException|InvitationExpiredException $e) {
            [$status, $code] = $this->statusFor($e);

            return response()->json([
                'message' => $e->getMessage(),
                'code' => $code,
            ], $status);
        }

        return response()->json([
            'tenant' => [
                'id' => $invitation->tenant_id,
                'name' => $invitation->tenant->name,
            ],
            'email' => $invitation->email,
            'role' => $invitation->role->value,
            'expires_at' => $invitation->expires_at,
        ]);
    }

    public function accept(Request $request, string $token): JsonResponse
    {
        try {
            $invitation = $this->invitationService->accept($request->user(), $token);
        } catch (InvitationNotFoundException) {
            throw new NotFoundHttpException('Invitación no encontrada.');
        } catch (InvitationEmailMismatchException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'INVITATION_EMAIL_MISMATCH',
            ], 403);
        } catch (InvitationAlreadyAcceptedException|InvitationRevokedException|InvitationExpiredException $e) {
            [$status, $code] = $this->statusFor($e);

            return response()->json([
                'message' => $e->getMessage(),
                'code' => $code,
            ], $status);
        }

        return response()->json([
            'message' => 'Invitación aceptada.',
            'tenant_id' => $invitation->tenant_id,
            'role' => $invitation->role->value,
        ]);
    }

    /**
     * @return array{int, string}
     */
    private function statusFor(InvitationAlreadyAcceptedException|InvitationRevokedException|InvitationExpiredException $e): array
    {
        return match ($e::class) {
            InvitationAlreadyAcceptedException::class => [409, 'INVITATION_ALREADY_ACCEPTED'],
            InvitationRevokedException::class => [410, 'INVITATION_REVOKED'],
            default => [410, 'INVITATION_EXPIRED'],
        };
    }
}
