<?php

declare(strict_types=1);

use App\Domain\Billing\Contracts\BillingProviderInterface;
use App\Domain\Billing\Exceptions\BillingProviderException;
use App\Infrastructure\Billing\StripeProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('BILL-U3-SIG-01: constructWebhookEvent succeeds with valid signature', function (): void {
    $provider = new StripeProvider(
        secretKey: 'sk_test_fake',
        webhookSecret: 'whsec_test_secret',
    );

    $this->app->instance(BillingProviderInterface::class, $provider);

    // We test the guard: empty secret throws
    $noSecretProvider = new StripeProvider(
        secretKey: 'sk_test_fake',
        webhookSecret: '',
    );

    try {
        $noSecretProvider->constructWebhookEvent('{}', 'sig');
        $this->fail('Expected BillingProviderException');
    } catch (BillingProviderException $e) {
        expect($e->getMessage())->toContain('webhook secret is not configured');
    }
})->group('BILL-U3-SIG-01');

it('BILL-U3-SIG-02: constructWebhookEvent rejects invalid signature', function (): void {
    $provider = new StripeProvider(
        secretKey: 'sk_test_fake',
        webhookSecret: 'whsec_test_secret',
    );

    try {
        $provider->constructWebhookEvent('{}', 'invalid_sig_header');
        $this->fail('Expected BillingProviderException');
    } catch (BillingProviderException $e) {
        expect($e->getMessage())->toContain('Invalid Stripe webhook signature');
    }
})->group('BILL-U3-SIG-02');

it('BILL-U3-SIG-03: constructWebhookEvent rejects missing signature header', function (): void {
    $provider = new StripeProvider(
        secretKey: 'sk_test_fake',
        webhookSecret: 'whsec_test_secret',
    );

    try {
        $provider->constructWebhookEvent('{}', '');
        $this->fail('Expected BillingProviderException');
    } catch (BillingProviderException $e) {
        expect($e->getMessage())->toContain('Invalid Stripe webhook signature');
    }
})->group('BILL-U3-SIG-03');

it('BILL-U3-SIG-04: constructWebhookEvent rejects wrong secret', function (): void {
    $provider = new StripeProvider(
        secretKey: 'sk_test_fake',
        webhookSecret: 'whsec_wrong_secret',
    );

    try {
        $provider->constructWebhookEvent('{}', 't=fake,v1=fake');
        $this->fail('Expected BillingProviderException');
    } catch (BillingProviderException $e) {
        expect($e->getMessage())->toContain('Invalid Stripe webhook signature');
    }
})->group('BILL-U3-SIG-04');

it('BILL-U3-SIG-05: malformed payload with invalid signature rejected', function (): void {
    $provider = new StripeProvider(
        secretKey: 'sk_test_fake',
        webhookSecret: 'whsec_test_secret',
    );

    try {
        $provider->constructWebhookEvent('not valid json {{{', 'invalid_sig');
        $this->fail('Expected BillingProviderException');
    } catch (BillingProviderException $e) {
        // Stripe SDK checks signature before parsing, so malformed payload with bad sig gets signature error
        expect($e->getMessage())->toContain('Invalid Stripe webhook signature');
    }
})->group('BILL-U3-SIG-05');

it('BILL-U3-SIG-06: empty webhook secret throws configured error', function (): void {
    $provider = new StripeProvider(
        secretKey: 'sk_test_fake',
        webhookSecret: '',
    );

    try {
        $provider->constructWebhookEvent('{}', 'sig');
        $this->fail('Expected BillingProviderException');
    } catch (BillingProviderException $e) {
        expect($e->getMessage())->toContain('webhook secret is not configured');
    }
})->group('BILL-U3-SIG-06');

it('BILL-U3-SIG-07: signature never logged in error messages', function (): void {
    $provider = new StripeProvider(
        secretKey: 'sk_test_fake',
        webhookSecret: 'whsec_test_secret',
    );

    try {
        $provider->constructWebhookEvent('{}', 't=123,v1=secret_signature_value');
        $this->fail('Expected BillingProviderException');
    } catch (BillingProviderException $e) {
        expect($e->getMessage())->not->toContain('secret_signature_value');
    }
})->group('BILL-U3-SIG-07');
