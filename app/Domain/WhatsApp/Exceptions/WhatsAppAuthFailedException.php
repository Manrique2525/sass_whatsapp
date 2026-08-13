<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Exceptions;

use App\Domain\WhatsApp\Enums\WhatsAppErrorCode;

/**
 * La autenticación contra Meta falló (token inválido/expirado/sin scope).
 */
final class WhatsAppAuthFailedException extends WhatsAppException
{
    public function __construct(string $message = 'La autenticación con WhatsApp falló.')
    {
        parent::__construct($message, WhatsAppErrorCode::AuthFailed, 401);
    }
}
