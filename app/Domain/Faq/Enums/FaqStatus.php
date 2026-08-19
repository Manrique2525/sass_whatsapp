<?php

declare(strict_types=1);

namespace App\Domain\Faq\Enums;

/**
 * Estado de una FAQ (FASE 18, ADR-069).
 *
 * active  — participa en matching, visible para el bot
 * inactive — no participa en matching, mantenida para reactivación futura
 */
enum FaqStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
        };
    }
}
