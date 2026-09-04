<?php

declare(strict_types=1);

use App\Domain\Users\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

test('el aviso de verificación se renderiza para un usuario no verificado', function (): void {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get('/verify-email')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Auth/VerifyEmail'));
});

test('un usuario verificado es redirigido al dashboard', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/verify-email')->assertRedirect(route('dashboard'));
});

test('el dashboard requiere verificación de email', function (): void {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get('/dashboard')->assertRedirect(route('verification.notice'));
});

test('un email puede verificarse mediante URL firmada', function (): void {
    $user = User::factory()->unverified()->create();

    $url = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    // Tras la verificación, el usuario entra al onboarding (tenants web son
    // provisionados en el signup, ADR-124).
    $this->actingAs($user)->get($url)->assertRedirect(route('onboarding'));

    expect($user->fresh()->email_verified_at)->not->toBeNull();
});

test('una URL firmada inválida no verifica el email', function (): void {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get('/email/verify/'.$user->id.'/sha1-invalido')
        ->assertForbidden();

    expect($user->fresh()->email_verified_at)->toBeNull();
});

test('el enlace de verificación puede reenviarse', function (): void {
    Notification::fake();

    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->post('/email/resend')
        ->assertSessionHas('status');

    Notification::assertSentTo($user, VerifyEmail::class);
});

test('el reenvío de verificación está limitado por tasa', function (): void {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user);

    for ($i = 0; $i < 6; $i++) {
        $this->post('/email/resend');
    }

    $this->post('/email/resend')->assertStatus(429);
});
