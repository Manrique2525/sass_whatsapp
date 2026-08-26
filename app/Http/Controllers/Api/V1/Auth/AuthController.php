<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Application\Audit\Services\AuditLogger;
use App\Application\Users\Services\AuthenticateUser;
use App\Application\Users\Services\AuthorizationService;
use App\Application\Users\Services\RegisterUser;
use App\Domain\Tenants\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\TenantResource;
use App\Http\Resources\UserResource;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class AuthController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly AuthorizationService $authorization,
    ) {}

    public function register(RegisterRequest $request, RegisterUser $registerUser): JsonResponse
    {
        $user = $registerUser->register(
            $request->validated('name'),
            $request->validated('email'),
            $request->validated('password'),
        );

        $user->sendEmailVerificationNotification();

        $tokenInstance = $user->createToken('api');
        $token = $tokenInstance->plainTextToken;
        $expiresAt = $tokenInstance->accessToken->expires_at;

        return response()->json([
            'message' => 'Usuario registrado.',
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt?->toISOString(),
            'expires_in' => $expiresAt !== null
                ? (int) Carbon::now()->diffInSeconds($expiresAt, false)
                : null,
            'user' => new UserResource($user),
        ], 201);
    }

    public function login(LoginRequest $request, AuthenticateUser $authenticateUser): JsonResponse
    {
        $user = $authenticateUser->authenticate(
            $request->validated('email'),
            $request->validated('password'),
        );

        if ($user === null) {
            $this->auditLogger->record(
                action: 'user.login_failed',
                data: ['reason' => 'invalid_credentials'],
            );

            throw ValidationException::withMessages([
                'email' => 'Las credenciales no coinciden con nuestros registros.',
            ]);
        }

        $this->auditLogger->record(
            action: 'user.login',
            data: ['email' => $user->email],
            actorUserId: $user->id,
        );

        $tokenInstance = $user->createToken('api');
        $token = $tokenInstance->plainTextToken;
        $expiresAt = $tokenInstance->accessToken->expires_at;

        return response()->json([
            'message' => 'Sesión iniciada.',
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt?->toISOString(),
            'expires_in' => $expiresAt !== null
                ? (int) Carbon::now()->diffInSeconds($expiresAt, false)
                : null,
            'user' => new UserResource($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentTenant = $user->current_tenant_id !== null
            ? Tenant::query()->find($user->current_tenant_id)
            : null;

        $role = $currentTenant !== null ? $user->roleForTenant($currentTenant->id) : null;

        return response()->json([
            'user' => new UserResource($user),
            'tenants' => TenantResource::collection($user->tenants()->orderBy('name')->get()),
            'current_tenant' => $currentTenant !== null ? new TenantResource($currentTenant) : null,
            'current_tenant_id' => $user->current_tenant_id,
            'current_role' => $role?->value,
            'roles' => $user->getRoleNames()->all(),
            'permissions' => $currentTenant !== null
                ? $this->authorization->permissionsForTenant($user, $currentTenant)
                : [],
            'is_super_admin' => $user->isSuperAdmin(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->auditLogger->record(
            action: 'user.logout',
            data: ['email' => $user->email],
            actorUserId: $user->id,
        );

        $user->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Sesión cerrada.',
        ]);
    }
}
