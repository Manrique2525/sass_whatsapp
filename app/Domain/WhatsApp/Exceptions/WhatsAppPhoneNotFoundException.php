<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Exceptions;

use App\Domain\WhatsApp\Enums\WhatsAppErrorCode;

/**
 * El `phone_number_id` recibido no está registrado en ningún tenant.
 *
 * En el webhook se traduce a `webhook_events.status=failed` con motivo
 * `unknown_phone_number_id` y se responde 200 igualmente (Meta no reintenta).
 */
final class WhatsAppPhoneNotFoundException extends WhatsAppException
{
    public function __construct(string $message = 'Número de WhatsApp no encontrado.')
    {
        parent::__construct($message, WhatsAppErrorCode::PhoneNotFound, 404);
    }
}
