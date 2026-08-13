<?php

declare(strict_types=1);

use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

test('la pantalla de registro se renderiza', function (): void {
    $this->get('/register')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Auth/Register'));
});

test('un nuevo usuario puede registrarse', function (): void {
    $this->post('/register', [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertRedirect(route('verification.notice'));

    $user = User::query()->where('email', 'jane@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->email_verified_at)->toBeNull()
        ->and(Hash::check('password123', $user->password))->toBeTrue()
        ->and($user->password)->not->toBe('password123');

    $this->assertAuthenticatedAs($user);
});

test('el email del usuario se guarda en minúsculas y recortado', function (): void {
    $this->post('/register', [
        'name' => 'Jane Doe',
        'email' => '  Jane@Example.COM ',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertRedirect(route('verification.notice'));

    expect(User::query()->where('email', 'jane@example.com')->exists())->toBeTrue();
});

test('no se permite registrar un email duplicado', function (): void {
    User::factory()->create(['email' => 'dup@example.com']);

    $this->post('/register', [
        'name' => 'Jane Doe',
        'email' => 'dup@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertSessionHasErrors('email');

    expect(User::query()->where('email', 'dup@example.com')->count())->toBe(1);
});

test('se valida la longitud mínima de la contraseña', function (): void {
    $this->post('/register', [
        'name' => 'Jane Doe',
        'email' => 'short@example.com',
        'password' => '123',
        'password_confirmation' => '123',
    ])->assertSessionHasErrors('password');
});

test('el registro está limitado por tasa', function (): void {
    for ($i = 0; $i < 6; $i++) {
        $this->post('/register', [
            'name' => 'Spam',
            'email' => "spam{$i}@example.com",
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);
    }

    $this->post('/register', [
        'name' => 'Spam',
        'email' => 'spam6@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertStatus(429);
});
