<?php

declare(strict_types=1);

use App\Application\Users\Services\AuthenticateUser;
use App\Application\Users\Services\RegisterUser;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

test('AuthenticateUser devuelve el usuario con credenciales correctas', function (): void {
    $user = User::factory()->create(['email' => 'jane@example.com']);

    $result = app(AuthenticateUser::class)->authenticate('jane@example.com', 'password');

    expect($result)->not->toBeNull()
        ->and($result->is($user))->toBeTrue();
});

test('AuthenticateUser es insensible a mayúsculas en el email', function (): void {
    User::factory()->create(['email' => 'jane@example.com']);

    $result = app(AuthenticateUser::class)->authenticate('JANE@EXAMPLE.COM', 'password');

    expect($result)->not->toBeNull();
});

test('AuthenticateUser devuelve null con contraseña incorrecta', function (): void {
    User::factory()->create(['email' => 'jane@example.com']);

    expect(app(AuthenticateUser::class)->authenticate('jane@example.com', 'wrong'))->toBeNull();
});

test('AuthenticateUser devuelve null con email inexistente', function (): void {
    expect(app(AuthenticateUser::class)->authenticate('ghost@example.com', 'password'))->toBeNull();
});

test('RegisterUser persiste el email en minúsculas y la contraseña hasheada', function (): void {
    app(RegisterUser::class)->register('Jane Doe', '  JANE@Example.COM ', 'password123');

    $user = User::query()->where('email', 'jane@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Jane Doe')
        ->and(Hash::check('password123', $user->password))->toBeTrue()
        ->and($user->password)->not->toBe('password123');
});

test('el password nunca se expone al serializar el modelo', function (): void {
    $user = User::factory()->create();

    $serialized = $user->toArray();

    expect($serialized)->not->toHaveKey('password')
        ->and($serialized)->not->toHaveKey('remember_token');
});
