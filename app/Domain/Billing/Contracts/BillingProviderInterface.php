<?php

declare(strict_types=1);

namespace App\Domain\Billing\Contracts;

use App\Domain\Billing\DTOs\BillingCustomerData;
use App\Domain\Billing\Exceptions\BillingProviderException;

/**
 * Contrato del proveedor de facturación (FASE 24 U1).
 *
 * El dominio depende de esta interfaz, nunca de un proveedor concreto.
 * Las implementaciones convierten excepciones del SDK a BillingProviderException.
 * Los IDs de Stripe (customer_id, price_id) se manipulan como strings puros;
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
     * Obtiene el nombre del provider (ej: 'stripe').
     */
    public function providerName(): string;
}
