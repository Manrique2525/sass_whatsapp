<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Enums;

/**
 * Estado de un número de WhatsApp del tenant (FASE 6).
 *
 * Un número desconectado DETIENE el envío (lo comprueba el engine de envío).
 * `banned` cubre números bloqueados por Meta.
 */
enum PhoneNumberStatus: string
{
    case Connected = 'connected';
    case Disconnected = 'disconnected';
    case Banned = 'banned';

    public function isConnected(): bool
    {
        return $this === self::Connected;
    }
}
