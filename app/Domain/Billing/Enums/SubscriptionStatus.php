<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * Subscription lifecycle states (FASE 23 U1, ADR-088).
 *
 * Closed enum — only states required for U1 data foundation.
 * FASE 24 (Stripe) may extend with additional states.
 */
enum SubscriptionStatus: string
{
    case Active = 'active';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Cancelled => 'Cancelled',
        };
    }
}
