<?php

declare(strict_types=1);

namespace Tests\Unit\WhatsApp;

use App\Domain\Messages\Enums\MessageMediaFailureReason;
use App\Domain\WhatsApp\Exceptions\WhatsAppMediaDownloadException;
use App\Infrastructure\WhatsApp\Security\SsrUrlGuard;

/**
 * Testea el guard SSRF de media (FASE 31 U5, ADR-121) con resolución DNS
 * inyectable para no tocar red.
 */
test('SSRF-1: acepta un host público https', function (): void {
    $guard = new SsrUrlGuard(
        resolver: static fn (string $host): array => $host === 'lookaside.facebook.com' ? ['31.13.24.7'] : [],
    );

    expect(fn (): mixed => $guard->assertSafe('https://lookaside.facebook.com/x/y.png'))
        ->not->toThrow(WhatsAppMediaDownloadException::class);
});

test('SSRF-2: rechaza IP privada/loopback/link-local/metadata', function (): void {
    foreach (['http://10.0.0.1/x', 'http://192.168.1.1/x', 'http://169.254.1.1/x', 'http://127.0.0.1/x', 'http://0.0.0.0/x', 'https://169.254.169.254/x'] as $url) {
        $guard = new SsrUrlGuard(resolver: static fn (string $h): array => [$h]);
        $rejected = false;

        try {
            $guard->assertSafe($url);
        } catch (WhatsAppMediaDownloadException $e) {
            $rejected = $e->reason() === MessageMediaFailureReason::SsrfRejected;
        }

        expect($rejected)->toBeTrue();
    }
});

test('SSRF-3: exige esquema https por defecto', function (): void {
    $guard = new SsrUrlGuard(resolver: static fn (string $h): array => ['31.13.24.7']);

    expect(fn (): mixed => $guard->assertSafe('http://lookaside.facebook.com/x.png'))
        ->toThrow(WhatsAppMediaDownloadException::class);
});

test('SSRF-4: rechaza host que resuelve a IP privada (DNS rebinding)', function (): void {
    $guard = new SsrUrlGuard(resolver: static fn (string $host): array => $host === 'evil.example.com' ? ['10.0.0.5'] : []);

    expect(fn (): mixed => $guard->assertSafe('https://evil.example.com/x'))
        ->toThrow(WhatsAppMediaDownloadException::class);
});

test('SSRF-5: fallo de resolución DNS es fallo seguro (rechaza)', function (): void {
    $guard = new SsrUrlGuard(resolver: static fn (string $host): array => []);

    expect(fn (): mixed => $guard->assertSafe('https://no-resolve.example.com/x'))
        ->toThrow(WhatsAppMediaDownloadException::class);
});
