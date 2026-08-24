<?php

declare(strict_types=1);

use App\Domain\Billing\DTOs\CheckoutSessionData;
use App\Domain\Billing\DTOs\PortalSessionData;
use App\Domain\Billing\Exceptions\BillingProviderException;
use App\Infrastructure\Billing\StripeProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| StripeProvider U2 Tests (FASE 24 U2, ADR-093)
|--------------------------------------------------------------------------
|
| BILL-U2-PROV-01..05 — Checkout + Portal session contract tests.
| Tests verify interface compliance and config behavior (no live Stripe calls).
| Corren en SQLite :memory:.
|
*/

it('BILL-U2-PROV-01: createCheckoutSession throws when secret key empty', function (): void {
    $provider = new StripeProvider(secretKey: '');

    $provider->createCheckoutSession([
        'customer' => 'cus_fake',
        'price' => 'price_fake',
        'quantity' => 1,
        'success_url' => 'https://example.com/success',
        'cancel_url' => 'https://example.com/cancel',
    ]);
})->group('BILL-U2-PROV-01')
    ->throws(BillingProviderException::class);

it('BILL-U2-PROV-02: createPortalSession throws when secret key empty', function (): void {
    $provider = new StripeProvider(secretKey: '');

    $provider->createPortalSession([
        'customer' => 'cus_fake',
        'return_url' => 'https://example.com',
    ]);
})->group('BILL-U2-PROV-02')
    ->throws(BillingProviderException::class);

it('BILL-U2-PROV-03: createCheckoutSession method signature matches interface', function (): void {
    $provider = new StripeProvider(secretKey: 'sk_test_fake');
    $reflection = new ReflectionClass($provider);
    $method = $reflection->getMethod('createCheckoutSession');

    expect($method->getReturnType()->getName())->toBe(CheckoutSessionData::class);
})->group('BILL-U2-PROV-03');

it('BILL-U2-PROV-04: createPortalSession method signature matches interface', function (): void {
    $provider = new StripeProvider(secretKey: 'sk_test_fake');
    $reflection = new ReflectionClass($provider);
    $method = $reflection->getMethod('createPortalSession');

    expect($method->getReturnType()->getName())->toBe(PortalSessionData::class);
})->group('BILL-U2-PROV-04');

it('BILL-U2-PROV-05: providerName returns stripe for all operations', function (): void {
    $provider = new StripeProvider(secretKey: 'sk_test_fake');

    expect($provider->providerName())->toBe('stripe');
})->group('BILL-U2-PROV-05');
