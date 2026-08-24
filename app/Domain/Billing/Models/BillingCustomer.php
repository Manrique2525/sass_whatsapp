<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Tenants\Traits\BelongsToTenant;
use Database\Factories\Domain\Billing\Models\BillingCustomerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant-scoped billing customer mapping (FASE 24 U1, ADR-092).
 *
 * Maps a tenant to their customer record in the billing provider (Stripe).
 * One row per (tenant_id, provider) — a tenant has at most one Stripe customer.
 * UNIQUE(provider, provider_customer_id) prevents duplicate provider IDs globally.
 *
 * No PII stored beyond what the provider already holds (no email column).
 */
final class BillingCustomer extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<BillingCustomerFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'billing_customers';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'provider',
        'provider_customer_id',
    ];

    protected function casts(): array
    {
        return [];
    }
}
