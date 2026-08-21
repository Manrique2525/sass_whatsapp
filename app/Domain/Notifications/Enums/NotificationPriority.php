<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Enums;

/**
 * Notification priority (FASE 22 U1, ADR-082).
 *
 * MVP: low, normal, high. No critical/severity framework needed yet.
 * Used for UI sorting and future email delivery decisions.
 */
enum NotificationPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Low',
            self::Normal => 'Normal',
            self::High => 'High',
        };
    }
}
