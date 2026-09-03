<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Exceptions;

use App\Domain\Messages\Enums\MessageMediaFailureReason;
use App\Domain\WhatsApp\Enums\WhatsAppErrorCode;

/**
 * Fallo en la descarga/validación de un media de Meta (FASE 31 U5, ADR-121).
 *
 * `reason` es el código seguro (`MessageMediaFailureReason`) que la capa de
 * aplicación persiste en `message_media.failure_reason`; el mensaje de la
 * excepción es genérico (nunca contenido/provider raw).
 */
final class WhatsAppMediaDownloadException extends WhatsAppException
{
    public function __construct(
        string $message,
        private readonly MessageMediaFailureReason $reason,
    ) {
        parent::__construct($message, WhatsAppErrorCode::MessageFailed, 502);
    }

    public function reason(): MessageMediaFailureReason
    {
        return $this->reason;
    }
}
