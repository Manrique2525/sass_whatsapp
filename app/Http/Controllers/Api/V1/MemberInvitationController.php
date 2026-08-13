<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Users\Services\InvitationService;
use App\Domain\Tenants\Exceptions\PermissionDeniedException;
use App\Domain\Tenants\Exceptions\TenantMembershipException;
use App\Domain\Tenants\Exceptions\TenantNotActiveException;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\InvitationStatus;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Exceptions\InvitationAlreadyPendingException;
use App\Domain\Users\Exceptions\InvitationExpiredException;
use App\Domain\Users\Exceptions\InvitationNotFoundException;
use App\Domain\Users\Exceptions\InvitationNotPendingException;
use App\Domain\Users\Exceptions\MemberAlreadyExistsException;
use App\Domain\Users\Exceptions\RoleChangeNotAllowedException;
use App\Domain\Users\Models\TenantInvitation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\StoreMemberInvitationRequest;
use App\Http\Resources\MemberInvitationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class MemberInvitationController extends Controller
{
    public function __construct(private readonly InvitationService $invitationService) {}

    /**
     * Invitaciones del tenant activo (para la vista de usuarios).
     */
    public function index(Request $request, Tenant $tenant): JsonResponse
    {
        try {
            // `InviteUsers` permite listar las invitaciones (gestión de la vista).
            $this->invitationService->authorizeList($request->user(), $tenant);
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'PERMISSION_DENIED',
            ], 403);
        } catch (TenantNotActiveException) {
            return response()->json([
                'message' => 'El tenant no está activo.',
                'code' => 'TENANT_NOT_ACTIVE',
            ], 409);
        }

        $invitations = TenantInvitation::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', InvitationStatus::Pending)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'invitations' => MemberInvitationResource::collection($invitations),
        ]);
    }

    public function store(StoreMemberInvitationRequest $request, Tenant $tenant): JsonResponse
    {
        try {
            $invitation = $this->invitationService->invite(
                $request->user(),
                $tenant,
                $request->validated('email'),
                UserRole::fromString($request->validated('role')),
            );
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'PERMISSION_DENIED',
            ], 403);
        } catch (TenantNotActiveException) {
            return response()->json([
                'message' => 'El tenant no está activo.',
                'code' => 'TENANT_NOT_ACTIVE',
            ], 409);
        } catch (MemberAlreadyExistsException|RoleChangeNotAllowedException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'INVITATION_NOT_ALLOWED',
            ], 422);
        } catch (InvitationAlreadyPendingException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'INVITATION_ALREADY_PENDING',
            ], 409);
        }

        return response()->json([
            'message' => 'Invitación enviada.',
            'invitation' => new MemberInvitationResource($invitation),
        ], 201);
    }

    public function revoke(Request $request, Tenant $tenant, TenantInvitation $invitation): JsonResponse
    {
        try {
            $this->invitationService->revoke($request->user(), $tenant, $invitation);
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (InvitationNotFoundException $e) {
            throw new NotFoundHttpException('Invitación no encontrada.');
        } catch (PermissionDeniedException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'PERMISSION_DENIED',
            ], 403);
        } catch (TenantNotActiveException) {
            return response()->json([
                'message' => 'El tenant no está activo.',
                'code' => 'TENANT_NOT_ACTIVE',
            ], 409);
        } catch (InvitationNotPendingException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'INVITATION_NOT_PENDING',
            ], 409);
        }

        return response()->json([
            'message' => 'Invitación revocada.',
        ]);
    }

    public function resend(Request $request, Tenant $tenant, TenantInvitation $invitation): JsonResponse
    {
        try {
            $this->invitationService->resend($request->user(), $tenant, $invitation);
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (InvitationNotFoundException $e) {
            throw new NotFoundHttpException('Invitación no encontrada.');
        } catch (PermissionDeniedException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'PERMISSION_DENIED',
            ], 403);
        } catch (TenantNotActiveException) {
            return response()->json([
                'message' => 'El tenant no está activo.',
                'code' => 'TENANT_NOT_ACTIVE',
            ], 409);
        } catch (InvitationNotPendingException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'INVITATION_NOT_PENDING',
            ], 409);
        } catch (InvitationExpiredException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'INVITATION_EXPIRED',
            ], 410);
        }

        return response()->json([
            'message' => 'Invitación reenviada.',
        ]);
    }
}
