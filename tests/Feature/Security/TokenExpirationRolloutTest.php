<?php

declare(strict_types=1);

use App\Domain\Users\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------
// ROLL-01: Token created >24h ago with expires_at=NULL is INVALID
// when global expiration=1440 (CRITICAL: validates created_at check)
// ---------------------------------------------------------------
test('F27-U3-ROLL-01: old token with null expires_at is invalid under global expiration', function (): void {
    $this->app['config']->set('sanctum.expiration', 1440);

    $user = User::factory()->create();
    $token = $user->createToken('api');
    $accessToken = $token->accessToken;

    // Simulate token created 25 hours ago
    DB::table('personal_access_tokens')
        ->where('id', $accessToken->id)
        ->update([
            'created_at' => Carbon::now()->subHours(25),
            'expires_at' => null,
        ]);

    // Forget the auth guard so it re-resolves from DB
    $this->app['auth']->forgetGuards();

    $response = $this->getJson('/api/v1/auth/me', [
        'Authorization' => 'Bearer '.$token->plainTextToken,
    ]);

    $response->assertStatus(401);
});

// ---------------------------------------------------------------
// ROLL-02: Token created <24h ago with expires_at=NULL is VALID
// ---------------------------------------------------------------
test('F27-U3-ROLL-02: recent token with null expires_at is valid under global expiration', function (): void {
    $this->app['config']->set('sanctum.expiration', 1440);

    $user = User::factory()->create();
    $token = $user->createToken('api')->plainTextToken;

    $this->getJson('/api/v1/auth/me', [
        'Authorization' => 'Bearer '.$token,
    ])->assertOk();
});

// ---------------------------------------------------------------
// ROLL-03: Token with expires_at in the past is INVALID
// even when global expiration would allow it
// ---------------------------------------------------------------
test('F27-U3-ROLL-03: token with past expires_at is invalid regardless of global expiration', function (): void {
    $this->app['config']->set('sanctum.expiration', 1440);

    $user = User::factory()->create();
    $token = $user->createToken('api');
    $accessToken = $token->accessToken;

    // Token created 1 hour ago (would pass global check) but expires_at is in the past
    $accessToken->update([
        'expires_at' => Carbon::now()->subMinute(),
    ]);

    $this->app['auth']->forgetGuards();

    $response = $this->getJson('/api/v1/auth/me', [
        'Authorization' => 'Bearer '.$token->plainTextToken,
    ]);

    $response->assertStatus(401);
});

// ---------------------------------------------------------------
// ROLL-04: Global expiration disabled (null) means tokens never
// expire based on created_at
// ---------------------------------------------------------------
test('F27-U3-ROLL-04: null expiration disables created_at check entirely', function (): void {
    $this->app['config']->set('sanctum.expiration', null);

    $user = User::factory()->create();
    $token = $user->createToken('api');
    $accessToken = $token->accessToken;

    // Token created 100 hours ago — should still be valid when expiration=null
    DB::table('personal_access_tokens')
        ->where('id', $accessToken->id)
        ->update([
            'created_at' => Carbon::now()->subHours(100),
            'expires_at' => null,
        ]);

    $this->app['auth']->forgetGuards();

    $response = $this->getJson('/api/v1/auth/me', [
        'Authorization' => 'Bearer '.$token->plainTextToken,
    ]);

    $response->assertOk();
});

// ---------------------------------------------------------------
// ROLL-05: Both checks enforced — old token with future expires_at
// still fails global age check
// ---------------------------------------------------------------
test('F27-U3-ROLL-05: old token with future expires_at fails global age check', function (): void {
    $this->app['config']->set('sanctum.expiration', 1440);

    $user = User::factory()->create();
    $token = $user->createToken('api');
    $accessToken = $token->accessToken;

    // Old token but with explicit future expires_at
    DB::table('personal_access_tokens')
        ->where('id', $accessToken->id)
        ->update([
            'created_at' => Carbon::now()->subHours(25),
            'expires_at' => Carbon::now()->addDay(),
        ]);

    $this->app['auth']->forgetGuards();

    $response = $this->getJson('/api/v1/auth/me', [
        'Authorization' => 'Bearer '.$token->plainTextToken,
    ]);

    // Global expiration check fails on created_at — both checks are ANDed
    $response->assertStatus(401);
});

// ---------------------------------------------------------------
// ROLL-06: Login after deploy returns new token with correct
// expires_at matching validation semantics
// ---------------------------------------------------------------
test('F27-U3-ROLL-06: login token metadata matches actual validation', function (): void {
    $this->app['config']->set('sanctum.expiration', 1440);

    $user = User::factory()->create(['email' => 'roll06@test.com']);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'roll06@test.com',
        'password' => 'password',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['token', 'token_type', 'expires_at', 'expires_in']);

    // The token from login should work immediately
    $token = $response->json('token');
    $this->app['auth']->forgetGuards();

    $this->getJson('/api/v1/auth/me', [
        'Authorization' => 'Bearer '.$token,
    ])->assertOk();
});
