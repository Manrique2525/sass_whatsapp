<?php

declare(strict_types=1);

use App\Application\Platform\Services\PlatformMfaService;
use App\Application\Users\Services\TenantRoleManager;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OTPHP\TOTP;

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

test('un super admin inicia sesión en Platform aunque no tenga tenant', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = User::factory()->create(['password' => 'password123']);
    app(TenantRoleManager::class)->assignGlobalRole($user, UserRole::SuperAdmin);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password123',
    ])->assertRedirect('/platform');

    expect($user->fresh()->tenantUsers)->toHaveCount(0)
        ->and($user->fresh()->current_tenant_id)->toBeNull();
    $this->get('/platform')->assertRedirect('/platform/security');
});

test('un intended tenant no puede desviar a un super admin fuera de Platform', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = User::factory()->create(['password' => 'password123']);
    app(TenantRoleManager::class)->assignGlobalRole($user, UserRole::SuperAdmin);

    $this->withSession(['url.intended' => '/dashboard'])
        ->post('/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertRedirect('/platform');
});

test('un super admin con MFA habilitado entra en el challenge de Platform', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = User::factory()->create(['password' => 'password123']);
    app(TenantRoleManager::class)->assignGlobalRole($user, UserRole::SuperAdmin);
    $service = app(PlatformMfaService::class);
    $enrollment = $service->beginEnrollment($user);
    $totp = TOTP::createFromSecret($enrollment['secret']);
    $service->confirmEnrollment($user, $totp->now());

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password123',
    ])->assertRedirect('/platform');

    $this->get('/platform')->assertRedirect('/platform/security/challenge');
});

test('un super admin con MFA verificado puede acceder al dashboard de Platform', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = User::factory()->create(['password' => 'password123']);
    app(TenantRoleManager::class)->assignGlobalRole($user, UserRole::SuperAdmin);
    $service = app(PlatformMfaService::class);
    $enrollment = $service->beginEnrollment($user);
    $totp = TOTP::createFromSecret($enrollment['secret']);
    $service->confirmEnrollment($user, $totp->now());

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password123',
    ])->assertRedirect('/platform');

    $this->withSession([
        'platform_mfa_verified_user_id' => $user->id,
        'platform_mfa_verified_at' => now()->timestamp,
    ])->get('/platform')->assertOk();
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
    $this->seed(PlanSeeder::class);

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
