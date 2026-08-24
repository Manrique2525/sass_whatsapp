<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Billing\Models;

use App\Domain\Billing\Enums\WebhookEventStatus;
use App\Domain\Billing\Models\BillingWebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillingWebhookEvent>
 */
class BillingWebhookEventFactory extends Factory
{
    protected $model = BillingWebhookEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'stripe',
            'provider_event_id' => 'evt_'.fake()->bothify('????????????????'),
            'type' => 'checkout.session.completed',
            'status' => WebhookEventStatus::Pending,
            'provider_created_at' => fake()->dateTimeBetween('-1 hour', 'now'),
            'tenant_id' => null,
            'billing_customer_id' => null,
            'error_code' => null,
        ];
    }

    public function processed(): static
    {
        return $this->state(fn () => ['status' => WebhookEventStatus::Processed]);
    }

    public function failed(string $errorCode = 'PROCESSING_ERROR'): static
    {
        return $this->state(fn () => [
            'status' => WebhookEventStatus::Failed,
            'error_code' => $errorCode,
        ]);
    }
}
