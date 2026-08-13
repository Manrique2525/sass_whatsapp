<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Exceptions;

use App\Domain\WhatsApp\Enums\WhatsAppErrorCode;
use RuntimeException;

/**
 * Base de las excepciones del módulo WhatsApp (FASE 6).
 *
 * Cada excepción lleva un código estable (`WhatsAppErrorCode`) y el status HTTP
 * sugerido para la API. El webhook usa estas excepciones de forma interna
 * (se traducen a la respuesta adecuada sin exponer detalle de Meta).
 */
abstract class WhatsAppException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly WhatsAppErrorCode $errorCode,
        int $status = 400,
    ) {
        parent::__construct($message, $status);
    }

    public function errorCode(): WhatsAppErrorCode
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return (int) $this->getCode();
    }
}
