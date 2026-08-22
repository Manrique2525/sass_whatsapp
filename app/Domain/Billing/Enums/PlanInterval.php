<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * Billing interval for a plan (FASE 23 U1, ADR-088).
 *
 * Closed enum — only intervals required for U1 data foundation.
 * FASE 24 (Stripe) may extend with additional intervals.
 */
enum PlanInterval: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly',
            self::Yearly => 'Yearly',
        };
    }
}
