<?php

declare(strict_types=1);

namespace App\Infrastructure\Billing;

use App\Domain\Billing\Contracts\BillingProviderInterface;
use App\Domain\Billing\DTOs\BillingCustomerData;
use App\Domain\Billing\DTOs\CheckoutSessionData;
use App\Domain\Billing\DTOs\PortalSessionData;
use App\Domain\Billing\Exceptions\BillingProviderException;
use Stripe\BillingPortal;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Customer;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Price;
use Stripe\Stripe;
use Stripe\Webhook;

/**
 * Implementación concreta del proveedor de facturación usando Stripe (FASE 24 U1, extendido U2).
 *
 * Stateless respecto al tenant: la configuración (API key) se inyecta por constructor.
 * Los objetos de Stripe NUNCA escapan de esta clase; se devuelven DTOs del dominio.
 * Las excepciones de Stripe se traducen a BillingProviderException.
 */
final class StripeProvider implements BillingProviderInterface
{
    private string $secretKey;

    private string $webhookSecret;

    public function __construct(
        string $secretKey,
        string $webhookSecret = '',
    ) {
        $this->secretKey = $secretKey;
        $this->webhookSecret = $webhookSecret;
    }

    public function createCustomer(array $params): BillingCustomerData
    {
        $this->assertConfigured();

        try {
            $customer = Customer::create([
                'name' => $params['name'],
                'email' => $params['email'] ?? null,
                'metadata' => $params['metadata'] ?? [],
            ]);

            return BillingCustomerData::fromProvider([
                'id' => $customer->id,
                'provider' => 'stripe',
                'email' => $customer->email,
                'metadata' => (array) $customer->metadata,
            ]);
        } catch (ApiErrorException $e) {
            throw $this->mapException($e);
        }
    }

    public function retrieveCustomer(string $providerCustomerId): BillingCustomerData
    {
        $this->assertConfigured();

        try {
            $customer = Customer::retrieve($providerCustomerId);

            return BillingCustomerData::fromProvider([
                'id' => $customer->id,
                'provider' => 'stripe',
                'email' => $customer->email,
                'metadata' => (array) $customer->metadata,
            ]);
        } catch (ApiErrorException $e) {
            throw $this->mapException($e);
        }
    }

    public function validatePrice(string $priceId): bool
    {
        $this->assertConfigured();

        try {
            $price = Price::retrieve($priceId);

            return $price->active;
        } catch (ApiErrorException $e) {
            throw $this->mapException($e);
        }
    }

    public function createCheckoutSession(array $params): CheckoutSessionData
    {
        $this->assertConfigured();

        try {
            $session = CheckoutSession::create([
                'mode' => 'subscription',
                'customer' => $params['customer'],
                'line_items' => [
                    [
                        'price' => $params['price'],
                        'quantity' => $params['quantity'],
                    ],
                ],
                'success_url' => $params['success_url'],
                'cancel_url' => $params['cancel_url'],
                'metadata' => $params['metadata'] ?? [],
            ]);

            return CheckoutSessionData::fromProvider([
                'id' => $session->id,
                'url' => $session->url,
            ]);
        } catch (ApiErrorException $e) {
            throw $this->mapException($e);
        }
    }

    public function createPortalSession(array $params): PortalSessionData
    {
        $this->assertConfigured();

        try {
            $session = BillingPortal\Session::create([
                'customer' => $params['customer'],
                'return_url' => $params['return_url'],
            ]);

            return PortalSessionData::fromProvider([
                'id' => $session->id,
                'url' => $session->url,
            ]);
        } catch (ApiErrorException $e) {
            throw $this->mapException($e);
        }
    }

    public function verifyWebhookSignature(string $payload, string $sigHeader): bool
    {
        if ($this->webhookSecret === '') {
            return false;
        }

        try {
            Webhook::constructEvent($payload, $sigHeader, $this->webhookSecret);

            return true;
        } catch (SignatureVerificationException) {
            return false;
        } catch (\Throwable) {
            return false;
        }
    }

    public function providerName(): string
    {
        return 'stripe';
    }

    private function assertConfigured(): void
    {
        if ($this->secretKey === '') {
            throw new BillingProviderException('Stripe secret key is not configured. Set STRIPE_SECRET_KEY in .env.');
        }
    }

    private function mapException(ApiErrorException $e): BillingProviderException
    {
        $retryable = in_array($e->getHttpStatus(), [429, 500, 502, 503, 504], true);

        return new BillingProviderException(
            'Stripe API error: '.$e->getMessage(),
            $retryable,
            $e,
        );
    }
}
