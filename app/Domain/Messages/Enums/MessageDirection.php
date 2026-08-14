<?php

declare(strict_types=1);

namespace App\Domain\Messages\Enums;

/**
 * Dirección de un mensaje en la conversación (FASE 9, ADR-032).
 */
enum MessageDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';
}
