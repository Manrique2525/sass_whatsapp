<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| FASE 27 U1 — Session Configuration Tests
|--------------------------------------------------------------------------
|
| Verifies that session security settings are properly configured.
|
*/

it('F27-U1-SESS-01: session http_only defaults to true', function (): void {
    expect(config('session.http_only'))->toBeTrue();
});

it('F27-U1-SESS-02: session same_site defaults to lax', function (): void {
    expect(config('session.same_site'))->toBe('lax');
});

it('F27-U1-SESS-03: session encrypt config is readable', function (): void {
    $encrypted = config('session.encrypt');

    expect($encrypted)->toBeBool();
});

it('F27-U1-SESS-04: session secure cookie config is readable', function (): void {
    $secure = config('session.secure');

    expect($secure === null || is_bool($secure))->toBeTrue();
});

it('F27-U1-SESS-05: session lifetime is positive integer', function (): void {
    $lifetime = config('session.lifetime');

    expect($lifetime)->toBeInt()
        ->and($lifetime)->toBeGreaterThan(0);
});

it('F27-U1-SESS-06: .env.example documents SESSION_ENCRYPT=true for production', function (): void {
    $envExample = file_get_contents(base_path('.env.example'));

    expect($envExample)->toContain('SESSION_ENCRYPT=true');
});

it('F27-U1-SESS-07: .env.example documents SESSION_SECURE_COOKIE=true for production', function (): void {
    $envExample = file_get_contents(base_path('.env.example'));

    expect($envExample)->toContain('SESSION_SECURE_COOKIE=true');
});
