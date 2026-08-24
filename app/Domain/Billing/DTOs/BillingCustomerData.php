<?php

declare(strict_types=1);

namespace App\Domain\Billing\DTOs;

/**
 * Datos normalizados de un Customer del proveedor de facturación (FASE 24 U1).
 *
 * Value object puro: sin dependencias de Laravel ni del SDK.
 * Se usa para transferir datos entre Infrastructure y Application/Domain.
 */
final readonly class BillingCustomerData
{
    public function __construct(
        public string $providerCustomerId,
        public string $provider,
        public ?string $email,
        /** @var array<string, mixed> */
        public array $metadata,
    ) {}

    /**
     * Crea una instancia desde datos crudos del provider.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromProvider(array $data): self
    {
        return new self(
            providerCustomerId: (string) ($data['id'] ?? ''),
            provider: (string) ($data['provider'] ?? 'stripe'),
            email: $data['email'] ?? null,
            metadata: (array) ($data['metadata'] ?? []),
        );
    }
}
