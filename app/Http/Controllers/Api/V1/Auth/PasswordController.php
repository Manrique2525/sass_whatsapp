<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Application\Users\Services\ResetUserPassword;
use App\Application\Users\Services\SendPasswordResetLink;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use stdClass;

final class PasswordController extends Controller
{
    public function forgot(ForgotPasswordRequest $request, SendPasswordResetLink $sendPasswordResetLink): JsonResponse
    {
        $sendPasswordResetLink->send($request->validated('email'));

        return response()->json([
            'message' => 'Si el email existe, recibirás un enlace para restablecer tu contraseña.',
        ]);
    }

    public function reset(ResetPasswordRequest $request, ResetUserPassword $resetUserPassword): JsonResponse
    {
        $status = $resetUserPassword->reset(
            $request->validated('email'),
            $request->validated('token'),
            $request->validated('password'),
            $request->validated('password_confirmation'),
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => trans($status),
                'code' => 'INVALID_RESET_TOKEN',
                'errors' => new stdClass,
            ], 422);
        }

        return response()->json([
            'message' => trans($status),
        ]);
    }
}
