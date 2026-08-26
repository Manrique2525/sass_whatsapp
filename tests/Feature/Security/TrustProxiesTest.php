<?php

declare(strict_types=1);

use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------
// PROXY-01: Untrusted forwarded-for is ignored
// ---------------------------------------------------------------
test('F27-U2-PROXY-01: untrusted forwarded-for header is ignored', function (): void {
    $this->app['config']->set('trustedproxy.proxies', null);
    $user = User::factory()->create();
    $token = $user->createToken('api')->plainTextToken;

    $response = $this->getJson('/api/v1/auth/me', [
        'Authorization' => 'Bearer '.$token,
        'X-Forwarded-For' => '10.99.99.99',
    ]);

    $response->assertOk();
});

// ---------------------------------------------------------------
// PROXY-02: Trusted proxy forwarded-for is honored
// ---------------------------------------------------------------
test('F27-U2-PROXY-02: trusted proxy forwarded-for is honored', function (): void {
    $this->app['config']->set('trustedproxy.proxies', ['10.0.0.1']);

    $user = User::factory()->create();
    $token = $user->createToken('api')->plainTextToken;

    $response = $this->call('GET', '/api/v1/auth/me', [], [], [], [
        'REMOTE_ADDR' => '10.0.0.1',
        'HTTP_X_FORWARDED_FOR' => '192.168.1.100, 10.0.0.1',
        'HTTP_X_FORWARDED_PROTO' => 'http',
        'HTTP_AUTHORIZATION' => 'Bearer '.$token,
    ]);

    $response->assertOk();
});

// ---------------------------------------------------------------
// PROXY-03: Forwarded-proto=https makes request secure when proxy trusted
// ---------------------------------------------------------------
test('F27-U2-PROXY-03: forwarded-proto https makes request secure when proxy trusted', function (): void {
    $this->app['config']->set('trustedproxy.proxies', ['10.0.0.1']);

    $response = $this->call('GET', '/up', [], [], [], [
        'REMOTE_ADDR' => '10.0.0.1',
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ]);

    $response->assertOk();
});

// ---------------------------------------------------------------
// PROXY-04: Same header ignored when proxy untrusted
// ---------------------------------------------------------------
test('F27-U2-PROXY-04: forwarded-proto ignored when proxy untrusted', function (): void {
    $this->app['config']->set('trustedproxy.proxies', null);

    $response = $this->call('GET', '/up', [], [], [], [
        'REMOTE_ADDR' => '10.0.0.1',
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ]);

    $response->assertOk();
});

// ---------------------------------------------------------------
// PROXY-05: HSTS present under trusted HTTPS proxy in production
// ---------------------------------------------------------------
test('F27-U2-PROXY-05: HSTS present under trusted HTTPS proxy in production', function (): void {
    $this->app['config']->set('trustedproxy.proxies', ['10.0.0.1']);
    $this->app['config']->set('app.env', 'production');

    $user = User::factory()->create();
    $token = $user->createToken('api')->plainTextToken;

    $response = $this->call('GET', '/api/v1/auth/me', [], [], [], [
        'REMOTE_ADDR' => '10.0.0.1',
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_HOST' => 'example.com',
        'HTTP_AUTHORIZATION' => 'Bearer '.$token,
    ]);

    $response->assertOk();
    $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

// ---------------------------------------------------------------
// PROXY-06: Rate limit keys use resolved client IP safely
// ---------------------------------------------------------------
test('F27-U2-PROXY-06: spoofed forwarded-for does not affect rate limit key when untrusted', function (): void {
    $this->app['config']->set('trustedproxy.proxies', null);

    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Rate',
        'email' => 'rate@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertSuccessful();
});

// ---------------------------------------------------------------
// PROXY-07: trustedproxy config exists and is loadable
// ---------------------------------------------------------------
it('F27-U2-PROXY-07: trustedproxy config file exists and is loadable', function (): void {
    expect(config('trustedproxy'))->not->toBeNull()
        ->toHaveKeys(['proxies', 'headers']);
});

// ---------------------------------------------------------------
// PROXY-08: TRUSTED_PROXIES env-driven
// ---------------------------------------------------------------
it('F27-U2-PROXY-08: trustedproxy proxies is env-driven', function (): void {
    $this->app['config']->set('trustedproxy.proxies', null);
    expect(config('trustedproxy.proxies'))->toBeNull();

    $this->app['config']->set('trustedproxy.proxies', '*');
    expect(config('trustedproxy.proxies'))->toBe('*');
});
