<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Exceptions;

use App\Domain\WhatsApp\Enums\WhatsAppErrorCode;

/**
 * El payload del webhook no cumple el esquema esperado de Meta.
 *
 * Se loguea y se responde 200 (Meta no debe reintentar infinitamente); nunca
 * se traduce a un 500 que dispare reenvíos.
 */
final class WhatsAppWebhookInvalidException extends WhatsAppException
{
    public function __construct(string $message = 'Payload de webhook inválido.')
    {
        parent::__construct($message, WhatsAppErrorCode::WebhookInvalid, 200);
    }
}
