<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Exceptions;

use App\Domain\WhatsApp\Enums\WhatsAppErrorCode;

/**
 * Firma `X-Hub-Signature-256` inválida/ausente. Rechazo con 401 antes de
 * tocar nada (verificación HMAC-SHA256 sobre el body crudo con hash_equals).
 */
final class WhatsAppWebhookSignatureInvalidException extends WhatsAppException
{
    public function __construct(string $message = 'Firma de webhook inválida.')
    {
        parent::__construct($message, WhatsAppErrorCode::SignatureInvalid, 401);
    }
}
