<?php

declare(strict_types=1);

use App\Infrastructure\AI\OpenAIEmbeddingProvider;
use App\Infrastructure\AI\OpenAIProvider;
use App\Infrastructure\Billing\StripeProvider;
use App\Infrastructure\Logging\SafeLogContext;
use App\Infrastructure\WhatsApp\MetaWhatsAppProvider;

uses()->group('logging');

test('F28-U1-PII-01: Meta raw provider text with phone does not appear in logs', function (): void {
    $raw = 'Invalid parameter: To param: +5215555123456 is not valid';
    $sanitized = SafeLogContext::sanitizeProviderMessage($raw);

    expect($sanitized)->not->toContain('+5215555123456');
    expect($sanitized)->toContain('[PHONE]');
});

test('F28-U1-PII-02: OpenAI raw error with API key not emitted', function (): void {
    $raw = 'Incorrect API key provided: sk-proj1234567890abcdefghijklmnopqrstuvwxyz';
    $sanitized = SafeLogContext::sanitizeProviderMessage($raw);

    expect($sanitized)->not->toContain('sk-proj1234567890');
    expect($sanitized)->toContain('[REDACTED]');
});

test('F28-U1-PII-03: Stripe sensitive content scrubbed', function (): void {
    $raw = 'No such customer: cus_OldQJ4R5tGh7iB; key=sk_live_abc123def456';
    $sanitized = SafeLogContext::sanitizeProviderMessage($raw);

    expect($sanitized)->not->toContain('sk_live_abc123def456');
    expect($sanitized)->toContain('[REDACTED]');
});

test('F28-U1-PII-04: Authorization header never emitted in raw form', function (): void {
    $raw = 'Request failed with header: Bearer eyJhbGciOiJIUzI1NiJ9.data.sig';
    $sanitized = SafeLogContext::sanitizeProviderMessage($raw);

    expect($sanitized)->not->toContain('eyJhbGciOiJIUzI1NiJ9.data.sig');
    expect($sanitized)->toContain('Bearer [REDACTED]');
});

test('F28-U1-PII-05: message body not emitted automatically in provider logs', function (): void {
    // Verify that Log:: calls in providers use structured context, not raw bodies
    $providerClasses = [
        MetaWhatsAppProvider::class,
        OpenAIProvider::class,
        OpenAIEmbeddingProvider::class,
        StripeProvider::class,
    ];

    foreach ($providerClasses as $class) {
        $reflection = new ReflectionClass($class);
        $source = file_get_contents($reflection->getFileName());

        // Should not have Log:: calls that dump full request/response bodies
        // Provider logs should use structured key-value context only
        expect($source)->not->toContain("Log::info('full_body'");
        expect($source)->not->toContain('Log::debug($response->body())');
        expect($source)->not->toContain('Log::info($request->body())');
    }
});
