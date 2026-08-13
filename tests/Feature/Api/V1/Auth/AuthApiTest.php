<?php

declare(strict_types=1);

use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Domain\Users\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

test('registro vía API devuelve 201 con token y usuario', function (): void {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure(['message', 'token', 'user' => ['id', 'name', 'email']]);

    expect(User::query()->where('email', 'jane@example.com')->exists())->toBeTrue();
});

test('registro vía API devuelve error estándar ante validación fallida', function (): void {
    $this->postJson('/api/v1/auth/register', [
        'name' => 'Jane Doe',
        'email' => 'no-es-un-email',
        'password' => '123',
        'password_confirmation' => '456',
    ])->assertStatus(422)
        ->assertJson([
            'code' => 'VALIDATION_ERROR',
            'errors' => ['email' => []],
        ]);
});

test('login vía API devuelve token', function (): void {
    $user = User::factory()->create(['email' => 'jane@example.com']);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'jane@example.com',
        'password' => 'password',
    ])->assertOk()
        ->assertJsonStructure(['message', 'token', 'user' => ['id', 'name', 'email']]);
});

test('login vía API con credenciales incorrectas devuelve 422', function (): void {
    User::factory()->create(['email' => 'jane@example.com']);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'jane@example.com',
        'password' => 'wrong-password',
    ])->assertStatus(422)
        ->assertJson(['code' => 'VALIDATION_ERROR']);
});

test('me requiere autenticación y devuelve error estándar', function (): void {
    $this->getJson('/api/v1/auth/me')->assertStatus(401)
        ->assertJson(['code' => 'UNAUTHENTICATED']);
});

test('me devuelve el usuario autenticado', function (): void {
    $user = User::factory()->create(['email' => 'jane@example.com']);
    $token = $user->createToken('api')->plainTextToken;

    $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonPath('user.email', 'jane@example.com')
        ->assertJsonPath('tenants', [])
        ->assertJsonPath('current_tenant_id', null)
        ->assertJsonPath('roles', []);
});

test('me incluye los tenants del usuario con su rol', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $user = User::factory()->create();
    $user->tenants()->attach($tenantA, ['role' => 'owner']);
    $user->tenants()->attach($tenantB, ['role' => 'agent']);
    $token = $user->createToken('api')->plainTextToken;

    $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonCount(2, 'tenants')
        ->assertJsonFragment(['id' => $tenantA->id, 'role' => 'owner'])
        ->assertJsonFragment(['id' => $tenantB->id, 'role' => 'agent']);
});

test('logout revoca el token actual', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken('api')->plainTextToken;

    $this->postJson('/api/v1/auth/logout', [], ['Authorization' => 'Bearer '.$token])
        ->assertOk();

    $this->app['auth']->forgetGuards();

    $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$token])
        ->assertStatus(401);

    expect($user->tokens()->count())->toBe(0);
});

test('solicitar reset para email inexistente no revela su existencia', function (): void {
    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ghost@example.com'])
        ->assertOk()
        ->assertJson(['message' => 'Si el email existe, recibirás un enlace para restablecer tu contraseña.']);
});

test('reset con token inválido devuelve INVALID_RESET_TOKEN', function (): void {
    $this->postJson('/api/v1/auth/reset-password', [
        'token' => 'token-invalido',
        'email' => 'jane@example.com',
        'password' => 'nueva-password',
        'password_confirmation' => 'nueva-password',
    ])->assertStatus(422)
        ->assertJson(['code' => 'INVALID_RESET_TOKEN']);
});

test('reset con token válido restablece la contraseña', function (): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'jane@example.com']);

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'jane@example.com']);

    $token = null;
    Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use (&$token): bool {
        $token = $notification->getToken();

        return true;
    });

    expect($token)->not->toBeNull();

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => $token,
        'email' => 'jane@example.com',
        'password' => 'nueva-password',
        'password_confirmation' => 'nueva-password',
    ])->assertOk();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'jane@example.com',
        'password' => 'nueva-password',
    ])->assertOk();
});

test('el registro vía API está limitado por tasa', function (): void {
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Spam',
            'email' => "spam{$i}@example.com",
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);
    }

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Spam',
        'email' => 'spam6@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertStatus(429)
        ->assertJson(['code' => 'RATE_LIMITED']);
});
