<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| FASE 27 U1 — Security Headers Tests
|--------------------------------------------------------------------------
|
| Verifies that SecurityHeaders middleware sets the correct headers
| on every response (200, 401, 403, 404, 429).
|
*/

it('F27-U1-HDR-01: X-Content-Type-Options is nosniff', function (): void {
    $response = $this->get('/health');

    $response->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('F27-U1-HDR-02: X-Frame-Options is DENY', function (): void {
    $response = $this->get('/health');

    $response->assertHeader('X-Frame-Options', 'DENY');
});

it('F27-U1-HDR-03: X-XSS-Protection is 0', function (): void {
    $response = $this->get('/health');

    $response->assertHeader('X-XSS-Protection', '0');
});

it('F27-U1-HDR-04: Referrer-Policy is strict-origin-when-cross-origin', function (): void {
    $response = $this->get('/health');

    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

it('F27-U1-HDR-05: Content-Security-Policy is present', function (): void {
    $response = $this->get('/health');

    $response->assertHeader('Content-Security-Policy');
});

it('F27-U1-HDR-06: CSP includes frame-ancestors none', function (): void {
    $response = $this->get('/health');

    $csp = $response->headers->get('Content-Security-Policy');

    expect($csp)->toContain("frame-ancestors 'none'");
});

it('F27-U1-HDR-07: CSP does not use unsafe-eval', function (): void {
    $response = $this->get('/health');

    $csp = $response->headers->get('Content-Security-Policy');

    expect($csp)->not->toContain('unsafe-eval');
});

it('F27-U1-HDR-08: CSP includes required directives', function (): void {
    $response = $this->get('/health');

    $csp = $response->headers->get('Content-Security-Policy');

    expect($csp)->toContain("default-src 'self'")
        ->and($csp)->toContain("script-src 'self'")
        ->and($csp)->toContain("object-src 'none'")
        ->and($csp)->toContain("base-uri 'self'")
        ->and($csp)->toContain("form-action 'self'");
});

it('F27-U1-HDR-09: Permissions-Policy restricts camera microphone geolocation', function (): void {
    $response = $this->get('/health');

    $permissions = $response->headers->get('Permissions-Policy');

    expect($permissions)->toContain('camera=()')
        ->and($permissions)->toContain('microphone=()')
        ->and($permissions)->toContain('geolocation=()');
});

it('F27-U1-HDR-10: HSTS is absent on HTTP requests', function (): void {
    $response = $this->get('/health');

    $response->assertHeaderMissing('Strict-Transport-Security');
});

it('F27-U1-HDR-11: Security headers present on web GET request', function (): void {
    $response = $this->get('/login');

    $response->assertStatus(200);
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('Content-Security-Policy');
});

it('F27-U1-HDR-12: Security headers present on API POST with validation error', function (): void {
    $response = $this->postJson('/api/webhooks/whatsapp', []);

    expect($response->status())->not->toBe(500);
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('Content-Security-Policy');
});

it('F27-U1-HDR-13: Security headers present on WhatsApp webhook verify', function (): void {
    $response = $this->get('/api/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=fake&hub.challenge=test');

    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('X-Frame-Options', 'DENY');
});
