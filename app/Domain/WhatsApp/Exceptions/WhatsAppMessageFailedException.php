<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Exceptions;

use App\Domain\WhatsApp\Enums\WhatsAppErrorCode;

/**
 * La llamada a la Graph API de Meta falló (envío, consulta, suscripción...).
 *
 * `retryable` distingue errores transitorios conocidos (5xx/429) de permanentes
 * (4xx de validación de Meta). `ambiguous` identifica una pérdida de transporte
 * donde Meta pudo aceptar el mensaje y por tanto no debe repetirse a ciegas.
 */
final class WhatsAppMessageFailedException extends WhatsAppException
{
    public function __construct(
        string $message,
        private readonly ?string $providerErrorCode = null,
        private readonly bool $retryable = true,
        private readonly bool $ambiguous = false,
        private readonly ?int $retryAfterSeconds = null,
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

    public function ambiguous(): bool
    {
        return $this->ambiguous;
    }

    public function retryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }
}
