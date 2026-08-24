<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * Subscription lifecycle states (FASE 23 U1, ADR-088, extended FASE 24 U1 ADR-092, U3 ADR-094).
 *
 * States:
 * - Active: subscription is live and billing normally
 * - Pending: trial or awaiting first payment confirmation
 * - PastDue: payment failed, subscription in grace period (synced from provider)
 * - Cancelled: subscription terminated (past due or manually cancelled)
 */
enum SubscriptionStatus: string
{
    case Active = 'active';
    case Pending = 'pending';
    case PastDue = 'past_due';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Pending => 'Pending',
            self::PastDue => 'Past Due',
            self::Cancelled => 'Cancelled',
        };
    }
}
