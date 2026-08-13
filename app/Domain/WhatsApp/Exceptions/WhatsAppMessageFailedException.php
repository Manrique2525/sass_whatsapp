<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Exceptions;

use App\Domain\WhatsApp\Enums\WhatsAppErrorCode;

/**
 * La llamada a la Graph API de Meta falló (envío, consulta, suscripción...).
 *
 * `retryable` distingue errores transitorios (timeout, 5xx, 429 → se pueden
 * reintentar) de permanentes (4xx de validación de Meta → NO reintentar).
 */
final class WhatsAppMessageFailedException extends WhatsAppException
{
    public function __construct(
        string $message,
        private readonly ?string $providerErrorCode = null,
        private readonly bool $retryable = true,
    ) {
        parent::__construct($message, WhatsAppErrorCode::MessageFailed, 502);
    }

    public function providerErrorCode(): ?string
    {
        return $this->providerErrorCode;
    }

    public function retryable(): bool
    {
        return $this->retryable;
    }
}
