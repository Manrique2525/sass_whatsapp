<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Enums;

/**
 * Estado de un intento de envío (FASE 6).
 */
enum MessageSendStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';
}
