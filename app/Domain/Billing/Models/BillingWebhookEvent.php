<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Billing\Enums\WebhookEventStatus;
use Database\Factories\Domain\Billing\Models\BillingWebhookEventFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Ledger of processed webhook events (FASE 24 U3, ADR-094).
 *
 * Append-only: records are created as pending, then updated to processed/failed.
 * UNIQUE(provider, provider_event_id) enforces idempotency.
 * No raw payload stored (PII-safe).
 */
final class BillingWebhookEvent extends Model
{
    /** @use HasFactory<BillingWebhookEventFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'billing_webhook_events';

    /** @var list<string> */
    protected $fillable = [
        'provider',
        'provider_event_id',
        'type',
        'status',
        'provider_created_at',
        'tenant_id',
        'billing_customer_id',
        'error_code',
    ];

    protected function casts(): array
    {
        return [
            'status' => WebhookEventStatus::class,
            'provider_created_at' => 'datetime',
        ];
    }
}
