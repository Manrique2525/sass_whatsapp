<?php

declare(strict_types=1);

use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------
// TOK-01: Token valid before expiration
// ---------------------------------------------------------------
test('F27-U2-TOK-01: token valid before expiration', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken('api')->plainTextToken;

    $response = $this->getJson('/api/v1/auth/me', [
        'Authorization' => 'Bearer '.$token,
    ]);

    $response->assertOk()
        ->assertJsonPath('user.email', $user->email);
});

// ---------------------------------------------------------------
// TOK-02: Token rejected after expiration
// ---------------------------------------------------------------
test('F27-U2-TOK-02: token rejected after expiration', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken('api');
    $accessToken = $token->accessToken;

    $accessToken->update([
        'expires_at' => Carbon::now()->subHour(),
    ]);

    $response = $this->getJson('/api/v1/auth/me', [
        'Authorization' => 'Bearer '.$token->plainTextToken,
    ]);

    $response->assertStatus(401);
});

// ---------------------------------------------------------------
// TOK-03: Expired token returns 401
// ---------------------------------------------------------------
test('F27-U2-TOK-03: expired token returns 401', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken('api');
    $accessToken = $token->accessToken;

    $accessToken->update([
        'expires_at' => Carbon::now()->subMinutes(5),
    ]);

    $this->getJson('/api/v1/auth/me', [
        'Authorization' => 'Bearer '.$token->plainTextToken,
    ])->assertStatus(401);
});

// ---------------------------------------------------------------
// TOK-04: Expired token does not leak reason/details
// ---------------------------------------------------------------
test('F27-U2-TOK-04: expired token does not leak reason in response body', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken('api');
    $accessToken = $token->accessToken;

    $accessToken->update([
        'expires_at' => Carbon::now()->subMinute(),
    ]);

    $response = $this->getJson('/api/v1/auth/me', [
        'Authorization' => 'Bearer '.$token->plainTextToken,
    ]);

    $response->assertStatus(401)
        ->assertJsonMissing(['trace', 'file', 'line'])
        ->assertJsonMissing(['reason' => 'expired']);
});

// ---------------------------------------------------------------
// TOK-05: Valid token still works when not expired
// ---------------------------------------------------------------
test('F27-U2-TOK-05: valid token still works when not expired', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken('api');
    $accessToken = $token->accessToken;

    $accessToken->update([
        'expires_at' => Carbon::now()->addHours(2),
    ]);

    $this->getJson('/api/v1/auth/me', [
        'Authorization' => 'Bearer '.$token->plainTextToken,
    ])->assertOk();
});

// ---------------------------------------------------------------
// TOK-06: Logout still revokes token
// ---------------------------------------------------------------
test('F27-U2-TOK-06: logout still revokes token', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken('api')->plainTextToken;

    $this->postJson('/api/v1/auth/logout', [], [
        'Authorization' => 'Bearer '.$token,
    ])->assertOk();

    $this->app['auth']->forgetGuards();

    $this->getJson('/api/v1/auth/me', [
        'Authorization' => 'Bearer '.$token,
    ])->assertStatus(401);

    expect($user->fresh()->tokens()->count())->toBe(0);
});

// ---------------------------------------------------------------
// TOK-07: Tenant context unchanged with token expiration
// ---------------------------------------------------------------
test('F27-U2-TOK-07: tenant context unchanged with token expiration', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    $user->tenants()->attach($tenant, ['role' => 'owner']);
    $user->forceFill(['current_tenant_id' => $tenant->id])->save();

    $token = $user->createToken('api')->plainTextToken;

    $this->getJson('/api/v1/auth/me', [
        'Authorization' => 'Bearer '.$token,
    ])->assertOk()
        ->assertJsonPath('current_tenant_id', $tenant->id)
        ->assertJsonPath('current_tenant.id', $tenant->id);
});

// ---------------------------------------------------------------
// TOK-08: Expiration config can be read/changed via env in test
// ---------------------------------------------------------------
test('F27-U2-TOK-08: expiration config is readable and configurable', function (): void {
    expect(config('sanctum.expiration'))->toBeInt()->toBeGreaterThan(0);

    $original = config('sanctum.expiration');

    $this->app['config']->set('sanctum.expiration', 60);
    expect(config('sanctum.expiration'))->toBe(60);

    // Restore original
    $this->app['config']->set('sanctum.expiration', $original);
    expect(config('sanctum.expiration'))->toBe($original);
});

// ---------------------------------------------------------------
// TOK-09: Register response includes token metadata
// ---------------------------------------------------------------
test('F27-U2-TOK-09: register response includes expires_at and expires_in', function (): void {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Token Test',
        'email' => 'toktest@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure(['message', 'token', 'token_type', 'expires_at', 'expires_in', 'user']);
});

// ---------------------------------------------------------------
// TOK-10: Login response includes token metadata
// ---------------------------------------------------------------
test('F27-U2-TOK-10: login response includes expires_at and expires_in', function (): void {
    $user = User::factory()->create(['email' => 'logintest@example.com']);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'logintest@example.com',
        'password' => 'password',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['message', 'token', 'token_type', 'expires_at', 'expires_in', 'user']);
});
