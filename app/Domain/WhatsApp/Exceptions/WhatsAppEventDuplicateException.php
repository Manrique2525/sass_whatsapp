<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Exceptions;

use App\Domain\WhatsApp\Enums\WhatsAppErrorCode;

/**
 * Evento de webhook ya registrado (`provider_event_id` duplicado).
 *
 * Marca interna: no es un error HTTP (el webhook responde 200). Se usa para
 * diferenciar el flujo de dedupe y registrar `webhook_events.duplicate`.
 */
final class WhatsAppEventDuplicateException extends WhatsAppException
{
    public function __construct(string $message = 'Evento de webhook duplicado.')
    {
        parent::__construct($message, WhatsAppErrorCode::EventDuplicate, 200);
    }
}
