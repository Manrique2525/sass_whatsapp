<?php

declare(strict_types=1);

use App\Domain\Flows\Exceptions\WebhookUrlBlockedException;
use App\Domain\Flows\Services\WebhookUrlGuard;

test('SSRF (UNIDAD 5): bloquea hosts locales y sus alias', function (): void {
    $guard = app(WebhookUrlGuard::class);

    foreach (['localhost', 'localhost.localdomain', 'mi.host.localhost', '0.0.0.0', '::1', '[::1]', '127.0.0.1', '127.0.0.53'] as $host) {
        try {
            $guard->assertAllowed("http://{$host}/hook");
            expect(true)->toBeFalse("El host '{$host}' debería estar bloqueado.");
        } catch (WebhookUrlBlockedException) {
            expect(true)->toBeTrue();
        }
    }
});

test('SSRF (UNIDAD 5): bloquea IPs privadas y reservadas como literal', function (): void {
    $guard = app(WebhookUrlGuard::class);

    foreach (['10.0.0.1', '10.255.255.254', '172.16.0.1', '172.31.255.254', '192.168.0.1', '192.168.255.254', '169.254.169.254', 'fc00::1', 'fe80::1'] as $ip) {
        try {
            $guard->assertAllowed("http://{$ip}/hook");
            expect(true)->toBeFalse("La IP '{$ip}' debería estar bloqueada.");
        } catch (WebhookUrlBlockedException) {
            expect(true)->toBeTrue();
        }
    }
});

test('SSRF (UNIDAD 5): una IP pública literal pasa el guard', function (): void {
    app(WebhookUrlGuard::class)->assertAllowed('http://93.184.216.34/hook');

    expect(true)->toBeTrue();
});

test('SSRF (UNIDAD 5): un URL sin host o inválido se bloquea', function (): void {
    $guard = app(WebhookUrlGuard::class);

    foreach (['http:///hook', 'ftp://example.com/hook', 'not-a-url'] as $url) {
        try {
            $guard->assertAllowed($url);
            expect(true)->toBeFalse("El URL '{$url}' debería estar bloqueado.");
        } catch (WebhookUrlBlockedException) {
            expect(true)->toBeTrue();
        }
    }
});

test('sanitizeForLog (UNIDAD 5): nunca deja userinfo, query ni fragment en logs', function (): void {
    expect(WebhookUrlGuard::sanitizeForLog('https://user:secreto@example.com:8443/hook?api_key=abc&token=xyz#frag'))
        ->toBe('https://example.com:8443/hook')
        ->and(WebhookUrlGuard::sanitizeForLog('https://example.com/hook/{{custom.plan}}?k=v'))
        ->toBe('https://example.com/hook/{{custom.plan}}')
        ->and(WebhookUrlGuard::sanitizeForLog('http://127.0.0.1:8080/admin'))
        ->toBe('http://127.0.0.1:8080/admin')
        ->and(WebhookUrlGuard::sanitizeForLog('no-es-un-url'))
        ->toBe('(url inválida)');
});
