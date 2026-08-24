<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * Webhook event processing status (FASE 24 U3, ADR-094).
 *
 * Events are inserted as pending, then marked processed or failed.
 * Failed events can be retried by Stripe.
 */
enum WebhookEventStatus: string
{
    case Pending = 'pending';
    case Processed = 'processed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processed => 'Processed',
            self::Failed => 'Failed',
        };
    }
}
