<?php

declare(strict_types=1);

use App\Domain\Billing\Contracts\BillingProviderInterface;
use App\Infrastructure\Billing\E2EBillingProvider;
use App\Infrastructure\Billing\StripeProvider;
use App\Providers\AppServiceProvider;
use App\Providers\E2EOnlyServiceProvider;

test('E2E billing provider returns deterministic synthetic sessions without Stripe', function (): void {
    $provider = new E2EBillingProvider;

    $checkout = $provider->createCheckoutSession([
        'customer' => 'e2e-customer-a',
        'price' => 'price_e2e_monthly',
        'quantity' => 1,
        'success_url' => 'http://localhost/success',
        'cancel_url' => 'http://localhost/cancel',
        'idempotency_key' => 'checkout:key',
    ]);
    $portal = $provider->createPortalSession(['customer' => 'e2e-customer-a', 'return_url' => 'http://localhost']);

    expect($provider->validatePrice('price_e2e_monthly'))->toBeTrue()
        ->and($provider->validatePrice('price_real'))->toBeFalse()
        ->and($checkout->url)->toBe('http://stripe-e2e.local/checkout/price_e2e_monthly')
        ->and($checkout->providerSessionId)->toBe($provider->createCheckoutSession([
            'customer' => 'e2e-customer-a',
            'price' => 'price_e2e_monthly',
            'quantity' => 1,
            'success_url' => 'http://localhost/success',
            'cancel_url' => 'http://localhost/cancel',
            'idempotency_key' => 'checkout:key',
        ])->providerSessionId)
        ->and($portal->url)->toBe('http://stripe-e2e.local/portal/e2e-customer-a');
});

test('E2E binding is opt-in and non-e2e keeps Stripe provider', function (): void {
    expect(app(BillingProviderInterface::class))->toBeInstanceOf(StripeProvider::class);

    app()->instance('env', 'e2e');
    (new E2EOnlyServiceProvider(app()))->register();

    expect(app(BillingProviderInterface::class))->toBeInstanceOf(E2EBillingProvider::class);

    app()->instance('env', 'testing');
    (new AppServiceProvider(app()))->register();
    expect(app(BillingProviderInterface::class))->toBeInstanceOf(StripeProvider::class);
});
