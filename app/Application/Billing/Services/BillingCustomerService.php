<?php

declare(strict_types=1);

namespace App\Application\Billing\Services;

use App\Domain\Billing\Contracts\BillingProviderInterface;
use App\Domain\Billing\Exceptions\BillingProviderException;
use App\Domain\Billing\Models\BillingCustomer;
use App\Domain\Tenants\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Billing customer resolution (FASE 24 U2, ADR-093).
 *
 * Ensures a billing customer exists for a tenant in the provider.
 * Handles creation race conditions with UNIQUE constraint + re-read.
 *
 * Single responsibility: tenant ↔ provider customer mapping.
 */
final class BillingCustomerService
{
    public function __construct(
        private readonly BillingProviderInterface $provider,
    ) {}

    /**
     * Find the billing customer for a tenant, if it exists.
     */
    public function findByTenant(Tenant $tenant): ?BillingCustomer
    {
        return BillingCustomer::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('provider', $this->provider->providerName())
            ->first();
    }

    /**
     * Ensure a billing customer exists for the tenant.
     *
     * Returns existing mapping if present; creates new customer + mapping if not.
     * Handles race condition: catches UNIQUE constraint violation and re-reads.
     *
     *
     * @throws BillingProviderException
     */
    public function ensureCustomer(Tenant $tenant): BillingCustomer
    {
        $existing = $this->findByTenant($tenant);

        if ($existing !== null) {
            return $existing;
        }

        return $this->createCustomer($tenant);
    }

    /**
     * Create a new billing customer mapping, handling race condition.
     */
    private function createCustomer(Tenant $tenant): BillingCustomer
    {
        try {
            return DB::transaction(function () use ($tenant): BillingCustomer {
                $providerData = $this->provider->createCustomer([
                    'name' => $tenant->name,
                    'metadata' => [
                        'tenant_id' => $tenant->id,
                    ],
                ]);

                return BillingCustomer::create([
                    'tenant_id' => $tenant->id,
                    'provider' => $this->provider->providerName(),
                    'provider_customer_id' => $providerData->providerCustomerId,
                ]);
            });
        } catch (QueryException $e) {
            // Race: another request created the mapping between our SELECT and INSERT.
            // UNIQUE(tenant_id, provider) or UNIQUE(provider, provider_customer_id) violation.
            // Re-read the existing mapping.
            $existing = $this->findByTenant($tenant);

            if ($existing !== null) {
                return $existing;
            }

            // If re-read still fails, re-throw (rare: truly conflicting provider_customer_id)
            throw $e;
        }
    }
}
