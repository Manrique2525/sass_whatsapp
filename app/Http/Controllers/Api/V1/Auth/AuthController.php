<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Application\Users\Services\AuthenticateUser;
use App\Application\Users\Services\RegisterUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\TenantResource;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class AuthController extends Controller
{
    public function register(RegisterRequest $request, RegisterUser $registerUser): JsonResponse
    {
        $user = $registerUser->register(
            $request->validated('name'),
            $request->validated('email'),
            $request->validated('password'),
        );

        $user->sendEmailVerificationNotification();

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'message' => 'Usuario registrado.',
            'token' => $token,
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
            throw ValidationException::withMessages([
                'email' => 'Las credenciales no coinciden con nuestros registros.',
            ]);
        }

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'message' => 'Sesión iniciada.',
            'token' => $token,
            'user' => new UserResource($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => new UserResource($user),
            'tenants' => TenantResource::collection($user->tenants()->orderBy('name')->get()),
            'current_tenant' => $user->currentTenant !== null ? new TenantResource($user->currentTenant) : null,
            'current_tenant_id' => $user->current_tenant_id,
            'roles' => $user->getRoleNames()->all(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Sesión cerrada.',
        ]);
    }
}
