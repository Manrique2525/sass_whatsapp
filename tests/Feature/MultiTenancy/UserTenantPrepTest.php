<?php

declare(strict_types=1);

use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Models\TenantUser;
use App\Domain\Users\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

test('un usuario puede pertenecer a varios tenants con rol por tenant', function (): void {
    $user = User::factory()->create();
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $tenantC = Tenant::factory()->create();

    $user->tenants()->attach($tenantA, ['role' => 'owner']);
    $user->tenants()->attach($tenantB, ['role' => 'admin']);
    $user->tenants()->attach($tenantC, ['role' => 'agent']);

    expect($user->tenantUsers()->count())->toBe(3);

    $tenants = $user->tenantUsers()->orderBy('tenant_id')->get();

    expect($tenants->pluck('role')->all())->toBe([UserRole::Owner, UserRole::Admin, UserRole::Agent])
        ->and($tenants->first()->role)->toBe(UserRole::Owner);
});

test('el rol del pivot se castea al enum UserRole', function (): void {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $pivot = TenantUser::query()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => 'agent']);

    expect($pivot->role)->toBe(UserRole::Agent)
        ->and($pivot->role->value)->toBe('agent');
});

test('los roles por tenant se materializan en spatie modo teams', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $user = User::factory()->create();
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $tenantC = Tenant::factory()->create();

    expect(Role::query()->where('name', 'owner')->exists())->toBeTrue();

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenantA->id);
    $user->assignRole('owner');

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenantB->id);
    $user->assignRole('admin');

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenantA->id);
    $user->unsetRelation('roles');
    expect($user->getRoleNames()->all())->toBe(['owner']);

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenantB->id);
    $user->unsetRelation('roles');
    expect($user->getRoleNames()->all())->toBe(['admin']);

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenantC->id);
    $user->unsetRelation('roles');
    expect($user->hasRole('owner'))->toBeFalse();

    // Restablece el override para no contaminar el resto del test.
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});
