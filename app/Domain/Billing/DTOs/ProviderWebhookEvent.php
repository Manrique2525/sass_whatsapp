<?php

declare(strict_types=1);

namespace App\Domain\Billing\DTOs;

/**
 * Normalized webhook event data from the billing provider (FASE 24 U3, ADR-094).
 *
 * Value object: no SDK dependencies. Extracted from the raw provider event.
 * Never contains raw payload or PII.
 */
final readonly class ProviderWebhookEvent
{
    public function __construct(
        public string $eventId,
        public string $type,
        public string $createdAt,
        public string $objectId,
        public ?string $customerId,
        /** @var array<string, mixed> */
        public array $data,
    ) {}

    /**
     * Create from Stripe-specific event data.
     *
     * @param  array{id: string, type: string, created: int, data: array{object: array<string, mixed>}}  $event
     */
    public static function fromStripe(array $event): self
    {
        $object = $event['data']['object'];

        return new self(
            eventId: (string) $event['id'],
            type: (string) $event['type'],
            createdAt: (string) $event['created'],
            objectId: (string) ($object['id'] ?? ''),
            customerId: isset($object['customer']) ? (string) $object['customer'] : null,
            data: $object,
        );
    }
}
