<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Exceptions;

use App\Domain\WhatsApp\Enums\WhatsAppErrorCode;

/**
 * Solo un template con estado `approved` se puede enviar; cualquier otro estado
 * se rechaza SIN llamar al provider (0 llamadas a Meta).
 */
final class WhatsAppTemplateNotApprovedException extends WhatsAppException
{
    public function __construct(string $message = 'El template no está aprobado para envío.')
    {
        parent::__construct($message, WhatsAppErrorCode::MessageFailed, 409);
    }
}
