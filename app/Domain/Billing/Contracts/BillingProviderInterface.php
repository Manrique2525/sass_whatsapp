<?php

declare(strict_types=1);

namespace App\Domain\Billing\Contracts;

use App\Domain\Billing\DTOs\BillingCustomerData;
use App\Domain\Billing\DTOs\CheckoutSessionData;
use App\Domain\Billing\DTOs\PortalSessionData;
use App\Domain\Billing\DTOs\ProviderWebhookEvent;
use App\Domain\Billing\Exceptions\BillingProviderException;

/**
 * Contrato del proveedor de facturación (FASE 24 U1, extendido U2).
 *
 * El dominio depende de esta interfaz, nunca de un proveedor concreto.
 * Las implementaciones convierten excepciones del SDK a BillingProviderException.
 * Los IDs del proveedor se manipulan como strings puros;
 * no se exponen objetos del SDK a domain/application.
 */
interface BillingProviderInterface
{
    /**
     * Crea un Customer en el proveedor.
     *
     * @param  array{name: string, email?: string, metadata?: array<string, string>}  $params
     *
     * @throws BillingProviderException
     */
    public function createCustomer(array $params): BillingCustomerData;

    /**
     * Recupera un Customer existente por su ID de proveedor.
     *
     * @throws BillingProviderException
     */
    public function retrieveCustomer(string $providerCustomerId): BillingCustomerData;

    /**
     * Valida que un Price ID exista y sea activo en el proveedor.
     *
     * @throws BillingProviderException
     */
    public function validatePrice(string $priceId): bool;

    /**
     * Crea una Checkout Session hosted en el proveedor.
     *
     * @param  array{customer: string, price: string, quantity: int, success_url: string, cancel_url: string, metadata?: array<string, string>}  $params
     *
     * @throws BillingProviderException
     */
    public function createCheckoutSession(array $params): CheckoutSessionData;

    /**
     * Crea una Portal Session para el customer en el proveedor.
     *
     * @param  array{customer: string, return_url: string}  $params
     *
     * @throws BillingProviderException
     */
    public function createPortalSession(array $params): PortalSessionData;

    /**
     * Obtiene el nombre del provider (ej: 'stripe').
     */
    public function providerName(): string;

    /**
     * Verify a webhook signature and return the parsed event data.
     *
     * Returns ProviderWebhookEvent DTO (safe, no SDK objects).
     * Throws BillingProviderException if signature is invalid, secret not configured,
     * or payload is malformed.
     *
     * @throws BillingProviderException
     */
    public function constructWebhookEvent(string $rawPayload, string $sigHeader): ProviderWebhookEvent;
}
