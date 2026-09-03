<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Exceptions;

use App\Domain\WhatsApp\Enums\WhatsAppErrorCode;

/**
 * El template no existe o no pertenece al tenant autorizado. Se usa 404 para
 * ocultar la existencia cross-tenant (mismo patrón que otros recursos).
 */
final class WhatsAppTemplateNotFoundException extends WhatsAppException
{
    public function __construct(string $message = 'Template de WhatsApp no encontrado.')
    {
        parent::__construct($message, WhatsAppErrorCode::MessageFailed, 404);
    }
}
