<?php

declare(strict_types=1);

namespace App\Application\Users\Services;

use App\Domain\Users\Models\User;

/**
 * Caso de uso: verificar el email de un usuario.
 */
final class VerifyUserEmail
{
    public function verify(User $user): void
    {
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }
    }
}
