<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Exceptions;

use App\Domain\WhatsApp\Enums\WhatsAppErrorCode;

/**
 * Las variables del template no validan contra su schema de componentes
 * (faltan, sobran o están malformadas). Se rechaza ANTES de llamar al provider
 * (0 llamadas a Meta).
 */
final class WhatsAppTemplateValidationException extends WhatsAppException
{
    public function __construct(string $message)
    {
        parent::__construct($message, WhatsAppErrorCode::MessageFailed, 422);
    }
}
