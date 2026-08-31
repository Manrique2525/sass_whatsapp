<?php

declare(strict_types=1);

use App\Domain\Billing\Contracts\BillingProviderInterface;
use App\Domain\Billing\Exceptions\BillingProviderException;
use App\Infrastructure\Billing\StripeProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\Stripe;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Stripe::setApiKey(null);
});

/*
|--------------------------------------------------------------------------
| StripeProvider Tests (FASE 24 U1, ADR-092)
|--------------------------------------------------------------------------
|
| BILL-U1-PROV-01..08 — Provider contract and behavior (no Stripe calls).
| All Stripe API calls are NOT mocked — we test config/validation only.
| Corren en SQLite :memory:.
|
*/

it('BILL-U1-PROV-01: StripeProvider implements BillingProviderInterface', function (): void {
    $provider = new StripeProvider(secretKey: 'sk_test_fake');

    expect($provider)->toBeInstanceOf(BillingProviderInterface::class);
})->group('BILL-U1-PROV-01');

it('BILL-U1-PROV-02: providerName returns stripe', function (): void {
    $provider = new StripeProvider(secretKey: 'sk_test_fake');

    expect($provider->providerName())->toBe('stripe');
})->group('BILL-U1-PROV-02');

it('BILL-U1-PROV-09: configures the Stripe SDK with the supplied secret key', function (): void {
    new StripeProvider(secretKey: 'sk_test_configured');

    expect(Stripe::getApiKey())->toBe('sk_test_configured');
})->group('BILL-U1-PROV-09');

it('BILL-U1-PROV-03: createCustomer throws when secret key empty', function (): void {
    $provider = new StripeProvider(secretKey: '');

    $this->expectException(BillingProviderException::class);
    $provider->createCustomer(['name' => 'Test']);
})->group('BILL-U1-PROV-03');

it('BILL-U1-PROV-04: retrieveCustomer throws when secret key empty', function (): void {
    $provider = new StripeProvider(secretKey: '');

    $this->expectException(BillingProviderException::class);
    $provider->retrieveCustomer('cus_fake');
})->group('BILL-U1-PROV-04');

it('BILL-U1-PROV-05: validatePrice throws when secret key empty', function (): void {
    $provider = new StripeProvider(secretKey: '');

    $this->expectException(BillingProviderException::class);
    $provider->validatePrice('price_fake');
})->group('BILL-U1-PROV-05');

it('BILL-U1-PROV-06: verifyWebhookSignature returns false when webhook secret empty', function (): void {
    $provider = new StripeProvider(secretKey: 'sk_test_fake', webhookSecret: '');

    expect($provider->verifyWebhookSignature('payload', 'sig'))->toBeFalse();
})->group('BILL-U1-PROV-06');

it('BILL-U1-PROV-07: verifyWebhookSignature returns false with invalid signature', function (): void {
    $provider = new StripeProvider(secretKey: 'sk_test_fake', webhookSecret: 'whsec_test');

    expect($provider->verifyWebhookSignature('payload', 'invalid_sig'))->toBeFalse();
})->group('BILL-U1-PROV-07');

it('BILL-U1-PROV-08: StripeProvider constructor accepts webhook secret', function (): void {
    $provider = new StripeProvider(
        secretKey: 'sk_test_fake',
        webhookSecret: 'whsec_test_secret',
    );

    expect($provider)->toBeInstanceOf(StripeProvider::class);
})->group('BILL-U1-PROV-08');
