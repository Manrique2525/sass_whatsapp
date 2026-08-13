<?php

declare(strict_types=1);

namespace App\Application\Users\Services;

use App\Domain\Users\Models\User;
use Illuminate\Support\Facades\Password;

/**
 * Caso de uso: restablecer la contraseña con token de un solo uso.
 *
 * El callback persiste la nueva contraseña (cast `hashed`) y revoca todos los
 * tokens Sanctum del usuario para invalidar sesiones API activas.
 *
 * @return string estado del broker (Password::PASSWORD_RESET / INVALID_*)
 */
final class ResetUserPassword
{
    public function reset(string $email, string $token, string $password, string $passwordConfirmation): string
    {
        return Password::broker()->reset(
            [
                'email' => mb_strtolower(trim($email)),
                'token' => $token,
                'password' => $password,
                'password_confirmation' => $passwordConfirmation,
            ],
            static function (User $user, string $newPassword): void {
                $user->forceFill(['password' => $newPassword])->save();

                $user->tokens()->delete();
            }
        );
    }
}
