<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Exceptions;

use App\Domain\WhatsApp\Enums\WhatsAppErrorCode;

/**
 * No hay cuenta/número conectado (o el token está revocado) para enviar.
 */
final class WhatsAppNotConnectedException extends WhatsAppException
{
    public function __construct(string $message = 'No hay una cuenta de WhatsApp conectada.')
    {
        parent::__construct($message, WhatsAppErrorCode::NotConnected, 409);
    }
}
