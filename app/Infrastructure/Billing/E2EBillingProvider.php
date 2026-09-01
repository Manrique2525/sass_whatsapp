<?php

declare(strict_types=1);

namespace App\Infrastructure\Billing;

use App\Domain\Billing\Contracts\BillingProviderInterface;
use App\Domain\Billing\DTOs\BillingCustomerData;
use App\Domain\Billing\DTOs\CheckoutSessionData;
use App\Domain\Billing\DTOs\PortalSessionData;
use App\Domain\Billing\DTOs\ProviderWebhookEvent;
use App\Domain\Billing\Exceptions\BillingProviderException;

/**
 * Deterministic billing boundary for APP_ENV=e2e only.
 * Never contacts Stripe and returns synthetic local URLs.
 */
final class E2EBillingProvider implements BillingProviderInterface
{
    public function createCustomer(array $params): BillingCustomerData
    {
        $tenantId = (string) ($params['metadata']['tenant_id'] ?? 'unknown');

        return BillingCustomerData::fromProvider([
            'id' => 'e2e-customer-'.$tenantId,
            'provider' => 'stripe-e2e',
            'metadata' => $params['metadata'] ?? [],
        ]);
    }

    public function retrieveCustomer(string $providerCustomerId): BillingCustomerData
    {
        return BillingCustomerData::fromProvider([
            'id' => $providerCustomerId,
            'provider' => 'stripe-e2e',
        ]);
    }

    public function validatePrice(string $priceId): bool
    {
        return str_starts_with($priceId, 'price_e2e_');
    }

    public function createCheckoutSession(array $params): CheckoutSessionData
    {
        return CheckoutSessionData::fromProvider([
            'id' => 'e2e-checkout-'.sha1((string) ($params['idempotency_key'] ?? $params['price'])),
            'url' => 'http://stripe-e2e.local/checkout/'.rawurlencode((string) $params['price']),
        ]);
    }

    public function createPortalSession(array $params): PortalSessionData
    {
        return PortalSessionData::fromProvider([
            'id' => 'e2e-portal-'.sha1($params['customer']),
            'url' => 'http://stripe-e2e.local/portal/'.rawurlencode($params['customer']),
        ]);
    }

    public function providerName(): string
    {
        return 'stripe-e2e';
    }

    public function constructWebhookEvent(string $rawPayload, string $sigHeader): ProviderWebhookEvent
    {
        throw new BillingProviderException('Stripe webhooks are not part of the E2E fake boundary.');
    }
}
