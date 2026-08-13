<?php

declare(strict_types=1);

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

    TenantUser::query()->create(['tenant_id' => 100, 'user_id' => $user->id, 'role' => 'owner']);
    TenantUser::query()->create(['tenant_id' => 200, 'user_id' => $user->id, 'role' => 'admin']);
    TenantUser::query()->create(['tenant_id' => 300, 'user_id' => $user->id, 'role' => 'agent']);

    expect($user->tenantUsers()->count())->toBe(3);

    $tenants = $user->tenantUsers()->orderBy('tenant_id')->get();

    expect($tenants->pluck('role')->all())->toBe([UserRole::Owner, UserRole::Admin, UserRole::Agent])
        ->and($tenants->first()->role)->toBe(UserRole::Owner);
});

test('el rol del pivot se castea al enum UserRole', function (): void {
    $user = User::factory()->create();
    $pivot = TenantUser::query()->create(['tenant_id' => 42, 'user_id' => $user->id, 'role' => 'agent']);

    expect($pivot->role)->toBe(UserRole::Agent)
        ->and($pivot->role->value)->toBe('agent');
});

test('los roles por tenant se materializan en spatie modo teams', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $user = User::factory()->create();

    expect(Role::query()->where('name', 'owner')->exists())->toBeTrue();

    app(PermissionRegistrar::class)->setPermissionsTeamId(100);
    $user->assignRole('owner');

    app(PermissionRegistrar::class)->setPermissionsTeamId(200);
    $user->assignRole('admin');

    app(PermissionRegistrar::class)->setPermissionsTeamId(100);
    $user->unsetRelation('roles');
    expect($user->getRoleNames()->all())->toBe(['owner']);

    app(PermissionRegistrar::class)->setPermissionsTeamId(200);
    $user->unsetRelation('roles');
    expect($user->getRoleNames()->all())->toBe(['admin']);

    $user->unsetRelation('roles');
    expect($user->hasRole('owner'))->toBeFalse();
});
