<?php

declare(strict_types=1);

namespace App\Application\Users\Services;

use App\Domain\Users\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Caso de uso: verificar credenciales de un usuario.
 *
 * Devuelve `null` tanto si el email no existe como si la contraseña es
 * incorrecta (mismo mensaje para no revelar qué credencial falló).
 */
final class AuthenticateUser
{
    public function authenticate(string $email, string $password): ?User
    {
        $user = User::query()
            ->where('email', mb_strtolower(trim($email)))
            ->first();

        if ($user === null || ! Hash::check($password, $user->password)) {
            return null;
        }

        return $user;
    }
}
