<?php

declare(strict_types=1);

namespace Tests\Traits;

use App\Domain\Billing\DTOs\ProviderWebhookEvent;
use App\Domain\Billing\Exceptions\BillingProviderException;

/**
 * Shared method for anonymous BillingProviderInterface fakes in tests.
 * Adds the constructWebhookEvent stub (throws by default).
 */
trait FakeBillingProviderMethods
{
    public function constructWebhookEvent(string $rawPayload, string $sigHeader): ProviderWebhookEvent
    {
        throw new BillingProviderException('Webhook verification not implemented in fake provider.');
    }
}
