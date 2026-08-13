<?php

declare(strict_types=1);

namespace App\Application\Users\Services;

use App\Domain\Users\Models\User;

/**
 * Caso de uso: registrar un nuevo usuario de plataforma.
 *
 * No valida (eso lo hace el FormRequest). El password se persiste con el cast
 * `hashed` de Eloquent: nunca se guarda en texto plano.
 */
final class RegisterUser
{
    public function register(string $name, string $email, string $password): User
    {
        return User::query()->create([
            'name' => trim($name),
            'email' => mb_strtolower(trim($email)),
            'password' => $password,
        ]);
    }
}
