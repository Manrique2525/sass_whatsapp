<?php

declare(strict_types=1);

use App\Application\Users\Services\TenantRoleManager;
use App\Domain\Tenants\Enums\TenantStatus;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function make_platform_admin(): User
{
    test()->seed(RolesAndPermissionsSeeder::class);

    $user = User::factory()->create();
    app(TenantRoleManager::class)->assignGlobalRole($user, UserRole::SuperAdmin);

    return $user->fresh();
}

test('guest cannot access the platform boundary', function (): void {
    $this->get('/platform')->assertRedirect('/login');
});

test('tenant roles cannot access the platform boundary', function (string $role): void {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();
    make_tenant_member($user, $tenant, $role);

    $this->actingAs($user)->get('/platform')->assertForbidden();
})->with(['owner', 'admin', 'agent']);

test('a super admin without tenant membership can access the platform', function (): void {
    $user = make_platform_admin();

    $this->actingAs($user)
        ->get('/platform')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Platform/Dashboard')
            ->where('auth.is_super_admin', true)
            ->where('auth.tenants', [])
            ->where('auth.current_tenant_id', null)
            ->where('auth.permissions', []));
});

test('platform dashboard has a named route and does not require tenant middleware', function (): void {
    $route = app('router')->getRoutes()->getByName('platform.dashboard');

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe('platform/dashboard')
        ->and($route->gatherMiddleware())->toContain('platform.admin')
        ->and($route->gatherMiddleware())->not->toContain('tenant');
});

test('global role does not elevate tenant permissions', function (): void {
    $user = make_platform_admin();
    $tenant = Tenant::factory()->create();
    make_tenant_member($user, $tenant, 'agent');

    $this->actingAs($user)
        ->get('/api/v1/tenants/'.$tenant->id.'/subscriptions')
        ->assertForbidden();
});

test('platform access remains independent from a suspended tenant', function (): void {
    $user = make_platform_admin();
    $tenant = Tenant::factory()->create(['status' => TenantStatus::Suspended]);
    make_tenant_member($user, $tenant, 'agent');

    $this->actingAs($user)->get('/platform/dashboard')->assertOk();
    expect(TenantContext::bound())->toBeFalse();
});
