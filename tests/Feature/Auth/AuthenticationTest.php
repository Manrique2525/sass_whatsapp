<?php

declare(strict_types=1);

use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('la pantalla de inicio de sesión se renderiza', function (): void {
    $this->get('/login')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Auth/Login'));
});

test('un usuario puede iniciar sesión', function (): void {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});

test('las credenciales incorrectas no autentican', function (): void {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('un email no registrado recibe el mismo mensaje de error', function (): void {
    $this->post('/login', [
        'email' => 'ghost@example.com',
        'password' => 'whatever',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('un usuario puede cerrar sesión', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/logout')->assertRedirect('/');

    $this->assertGuest();
});

test('la sesión se regenera tras el login', function (): void {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});

test('la ruta raíz muestra la landing pública', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Landing'));
});

test('el login está limitado por tasa', function (): void {
    $user = User::factory()->create();

    for ($i = 0; $i < 10; $i++) {
        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);
    }

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertStatus(429);
});
