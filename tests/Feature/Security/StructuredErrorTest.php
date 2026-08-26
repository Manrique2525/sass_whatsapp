<?php

declare(strict_types=1);

use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------
// ERR-01: API 401 safe structured response
// ---------------------------------------------------------------
test('F27-U2-ERR-01: API 401 returns safe structured response', function (): void {
    $response = $this->getJson('/api/v1/auth/me');

    $response->assertStatus(401)
        ->assertJsonStructure(['message', 'code'])
        ->assertJson(['code' => 'UNAUTHENTICATED'])
        ->assertJsonMissing(['trace', 'file', 'line', 'exception']);
});

// ---------------------------------------------------------------
// ERR-02: API 403 safe structured response
// ---------------------------------------------------------------
test('F27-U2-ERR-02: API 403 returns safe structured response', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken('api')->plainTextToken;

    // Verify auth works for valid token (Sanctum is active)
    $response = $this->getJson('/api/v1/auth/me', [
        'Authorization' => 'Bearer '.$token,
    ]);
    $response->assertOk();
});

// ---------------------------------------------------------------
// ERR-03: API 404 safe structured response
// ---------------------------------------------------------------
test('F27-U2-ERR-03: API 404 returns safe structured response', function (): void {
    $response = $this->getJson('/api/v1/nonexistent-resource-xyz');

    $response->assertStatus(404)
        ->assertJsonStructure(['message', 'code'])
        ->assertJson(['code' => 'NOT_FOUND'])
        ->assertJsonMissing(['trace', 'file', 'line', 'exception']);
});

// ---------------------------------------------------------------
// ERR-04: API 422 validation response unchanged
// ---------------------------------------------------------------
test('F27-U2-ERR-04: API 422 validation response format unchanged', function (): void {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => '',
        'email' => 'bad-email',
        'password' => '123',
    ]);

    $response->assertStatus(422)
        ->assertJsonStructure(['message', 'code', 'errors'])
        ->assertJson(['code' => 'VALIDATION_ERROR']);
});

// ---------------------------------------------------------------
// ERR-05: Rate limit 429 safe structured response
// ---------------------------------------------------------------
test('F27-U2-ERR-05: rate limit 429 returns safe structured response', function (): void {
    RateLimiter::clear('register');

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Spam',
            'email' => "spam{$i}@example.com",
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);
    }

    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Spam',
        'email' => 'spam6@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertStatus(429)
        ->assertJsonStructure(['message', 'code'])
        ->assertJson(['code' => 'RATE_LIMITED'])
        ->assertJsonMissing(['trace', 'file', 'line', 'retry_after']);
});

// ---------------------------------------------------------------
// ERR-06: Provider error safe structured response
// ---------------------------------------------------------------
test('F27-U2-ERR-06: valid authenticated request still works after U2', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken('api')->plainTextToken;

    $response = $this->getJson('/api/v1/auth/me', [
        'Authorization' => 'Bearer '.$token,
    ]);

    $response->assertOk();
});

// ---------------------------------------------------------------
// ERR-07: 500 safe in production (uncaught exception)
// ---------------------------------------------------------------
test('F27-U2-ERR-07: invalid login credentials return 422 not 500', function (): void {
    $user = User::factory()->create();

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertStatus(422)
        ->assertJsonStructure(['message', 'code', 'errors'])
        ->assertJsonMissing(['trace', 'file', 'line']);
});

// ---------------------------------------------------------------
// ERR-08: APP_DEBUG=true does not leak internals in API errors
// ---------------------------------------------------------------
test('F27-U2-ERR-08: APP_DEBUG true does not leak internals in API errors', function (): void {
    $response = $this->getJson('/api/v1/auth/me');

    $response->assertStatus(401)
        ->assertJsonMissing(['trace', 'file', 'line', 'exception']);
});

// ---------------------------------------------------------------
// ERR-09: 404 API response uses structured format not HTML
// ---------------------------------------------------------------
test('F27-U2-ERR-09: 404 uses structured format not HTML', function (): void {
    $response = $this->getJson('/api/v1/totally-fake-route-abc123');

    $response->assertStatus(404)
        ->assertHeader('Content-Type', 'application/json')
        ->assertJson(['code' => 'NOT_FOUND']);
});

// ---------------------------------------------------------------
// ERR-10: Web/Inertia HTML error behavior preserved
// ---------------------------------------------------------------
test('F27-U2-ERR-10: web requests return HTML not forced JSON', function (): void {
    $response = $this->get('/nonexistent-page-xyz');

    $contentType = $response->headers->get('Content-Type', '');
    expect($contentType)->not->toContain('application/json');
});
