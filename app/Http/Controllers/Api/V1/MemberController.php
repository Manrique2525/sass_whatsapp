<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Users\Services\MemberService;
use App\Domain\Tenants\Exceptions\PermissionDeniedException;
use App\Domain\Tenants\Exceptions\TenantMembershipException;
use App\Domain\Tenants\Exceptions\TenantNotActiveException;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Exceptions\RoleChangeNotAllowedException;
use App\Domain\Users\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\UpdateMemberRoleRequest;
use App\Http\Resources\MemberResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class MemberController extends Controller
{
    public function __construct(private readonly MemberService $memberService) {}

    /**
     * Miembros activos del tenant activo del usuario.
     */
    public function index(Request $request, Tenant $tenant): JsonResponse
    {
        try {
            $members = $this->memberService->list($request->user(), $tenant);
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        }

        return response()->json([
            'members' => MemberResource::collection($members),
        ]);
    }

    public function update(UpdateMemberRoleRequest $request, Tenant $tenant, User $user): JsonResponse
    {
        try {
            $membership = $this->memberService->changeRole(
                $request->user(),
                $tenant,
                $user,
                UserRole::fromString($request->validated('role')),
            );
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (RoleChangeNotAllowedException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'ROLE_CHANGE_NOT_ALLOWED',
            ], 422);
        }

        return response()->json([
            'message' => 'Rol actualizado.',
            'member' => new MemberResource($membership),
        ]);
    }

    public function destroy(Request $request, Tenant $tenant, User $user): JsonResponse
    {
        try {
            $this->memberService->remove($request->user(), $tenant, $user);
        } catch (TenantMembershipException) {
            throw new NotFoundHttpException('Tenant no encontrado.');
        } catch (PermissionDeniedException $e) {
            return $this->forbidden($e);
        } catch (TenantNotActiveException) {
            return $this->tenantNotActive();
        } catch (RoleChangeNotAllowedException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'ROLE_CHANGE_NOT_ALLOWED',
            ], 422);
        }

        return response()->json([
            'message' => 'Miembro removido.',
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
