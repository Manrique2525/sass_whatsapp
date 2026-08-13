<?php

declare(strict_types=1);

namespace App\Application\Users\Services;

use Illuminate\Support\Facades\Password;

/**
 * Caso de uso: enviar enlace de restablecimiento de contraseña.
 *
 * El broker de Laravel devuelve siempre `Password::RESET_LINK_SENT` aunque el
 * email no exista, de modo que la respuesta no revela si un email está
 * registrado.
 */
final class SendPasswordResetLink
{
    /**
     * @return string estado del broker (ver Password::RESET_LINK_SENT)
     */
    public function send(string $email): string
    {
        return Password::broker()->sendResetLink([
            'email' => mb_strtolower(trim($email)),
        ]);
    }
}
