<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Exceptions;

use App\Domain\WhatsApp\Enums\WhatsAppErrorCode;

/** Configuración local inválida para usar la Meta WhatsApp Cloud API. */
final class WhatsAppConfigurationException extends WhatsAppException
{
    public function __construct()
    {
        parent::__construct(
            'La configuración de WhatsApp no es válida.',
            WhatsAppErrorCode::ConfigurationInvalid,
            500,
        );
    }
}
