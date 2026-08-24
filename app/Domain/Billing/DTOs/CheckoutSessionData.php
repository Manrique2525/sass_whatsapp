<?php

declare(strict_types=1);

namespace App\Domain\Billing\DTOs;

/**
 * Datos normalizados de una Checkout Session del proveedor (FASE 24 U2).
 *
 * Value object puro: sin dependencias de Laravel ni del SDK.
 * Frontend recibe solo la URL para redirigir a Stripe hosted Checkout.
 */
final readonly class CheckoutSessionData
{
    public function __construct(
        public string $providerSessionId,
        public string $url,
    ) {}

    /**
     * Crea una instancia desde datos crudos del provider.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromProvider(array $data): self
    {
        return new self(
            providerSessionId: (string) ($data['id'] ?? ''),
            url: (string) ($data['url'] ?? ''),
        );
    }
}
