<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Enums;

/**
 * Tipo de evento de webhook de Meta (FASE 6).
 */
enum WebhookEventType: string
{
    case Message = 'message';
    case Status = 'status';
}
