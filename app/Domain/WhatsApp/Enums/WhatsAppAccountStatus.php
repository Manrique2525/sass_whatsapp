<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Enums;

/**
 * Estado de la cuenta de WhatsApp Business (WABA) de un tenant (FASE 6).
 */
enum WhatsAppAccountStatus: string
{
    case Connected = 'connected';
    case Disconnected = 'disconnected';

    public function isConnected(): bool
    {
        return $this === self::Connected;
    }
}
