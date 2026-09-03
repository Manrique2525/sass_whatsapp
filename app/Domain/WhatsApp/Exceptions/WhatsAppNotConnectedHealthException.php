<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Exceptions;

use App\Domain\WhatsApp\Enums\WhatsAppErrorCode;

/**
 * No hay cuenta/número de WhatsApp conectado para consultar su salud (FASE 31 U6).
 */
final class WhatsAppNotConnectedHealthException extends WhatsAppException
{
    public function __construct(string $message = 'No hay un número de WhatsApp conectado.')
    {
        parent::__construct($message, WhatsAppErrorCode::NotConnected, 409);
    }
}
