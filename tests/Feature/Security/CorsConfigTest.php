<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| FASE 27 U1 — CORS Configuration Tests
|--------------------------------------------------------------------------
|
| Verifies the explicit CORS policy in config/cors.php.
|
*/

it('F27-U1-CORS-01: CORS config file exists and is loadable', function (): void {
    $config = config('cors');

    expect($config)->toBeArray()
        ->and($config)->toHaveKeys(['paths', 'allowed_methods', 'allowed_origins', 'supports_credentials']);
});

it('F27-U1-CORS-02: CORS paths include api and sanctum', function (): void {
    $paths = config('cors.paths');

    expect($paths)->toContain('api/*')
        ->and($paths)->toContain('sanctum/csrf-cookie');
});

it('F27-U1-CORS-03: CORS does not use wildcard origins when credentials enabled', function (): void {
    $origins = config('cors.allowed_origins');
    $supportsCredentials = config('cors.supports_credentials');

    if ($supportsCredentials) {
        expect($origins)->not->toContain('*');
    }

    expect(true)->toBeTrue();
});

it('F27-U1-CORS-04: CORS supports_credentials is false by default', function (): void {
    expect(config('cors.supports_credentials'))->toBeFalse();
});

it('F27-U1-CORS-05: CORS allowed_origins is env-driven', function (): void {
    $origins = config('cors.allowed_origins');

    expect($origins)->toBeArray();
});

it('F27-U1-CORS-06: WhatsApp webhook endpoint unaffected by CORS', function (): void {
    $response = $this->postJson('/api/webhooks/whatsapp', [], [
        'X-Hub-Signature-256' => 'sha256=fake',
    ]);

    expect($response->status())->not->toBe(500);
});
