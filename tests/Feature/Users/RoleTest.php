<?php

declare(strict_types=1);

use App\Application\Users\Services\TenantRoleManager;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| ROLES 15-20: reglas de cambio de rol y remoción
|--------------------------------------------------------------------------
*/

test('ROLES-15: un owner no puede degradar al último owner (422)', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $this->actingAs($owner)
        ->patchJson('/api/v1/tenants/'.$tenant->id.'/users/'.$owner->id, ['role' => 'admin'])
        ->assertStatus(422)
        ->assertJson(['code' => 'ROLE_CHANGE_NOT_ALLOWED']);

    $this->assertDatabaseHas('tenant_users', [
        'tenant_id' => $tenant->id,
        'user_id' => $owner->id,
        'role' => 'owner',
    ]);
});

test('ROLES-16: un owner cambia agent → admin y el espejo spatie se actualiza', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    $agent = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');
    make_tenant_member($agent, $tenant, 'agent');

    app(TenantRoleManager::class)->syncRoles($agent, $tenant, UserRole::Agent);

    $this->actingAs($owner)
        ->patchJson('/api/v1/tenants/'.$tenant->id.'/users/'.$agent->id, ['role' => 'admin'])
        ->assertOk()
        ->assertJsonPath('member.role', 'admin');

    $this->assertDatabaseHas('tenant_users', [
        'tenant_id' => $tenant->id,
        'user_id' => $agent->id,
        'role' => 'admin',
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
    $agent = $agent->fresh();
    $agent->unsetRelation('roles');
    expect($agent->getRoleNames()->all())->toBe(['admin']);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'user.role_changed',
        'tenant_id' => $tenant->id,
    ]);
});

test('ROLES-17: un owner cambia admin → agent', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');
    make_tenant_member($admin, $tenant, 'admin');

    $this->actingAs($owner)
        ->patchJson('/api/v1/tenants/'.$tenant->id.'/users/'.$admin->id, ['role' => 'agent'])
        ->assertOk()
        ->assertJsonPath('member.role', 'agent');
});

test('ROLES-18: un admin no puede cambiar roles (403, sin roles.assign)', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = User::factory()->create();
    $agent = User::factory()->create();
    make_tenant_member($admin, $tenant, 'admin');
    make_tenant_member($agent, $tenant, 'agent');

    $this->actingAs($admin)
        ->patchJson('/api/v1/tenants/'.$tenant->id.'/users/'.$agent->id, ['role' => 'admin'])
        ->assertStatus(403)
        ->assertJson(['code' => 'PERMISSION_DENIED']);
});

test('ROLES-19: un admin puede remover a un agent (200)', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = User::factory()->create();
    $agent = User::factory()->create();
    make_tenant_member($admin, $tenant, 'admin');
    make_tenant_member($agent, $tenant, 'agent');

    $this->actingAs($admin)
        ->deleteJson('/api/v1/tenants/'.$tenant->id.'/users/'.$agent->id)
        ->assertOk();

    $this->assertDatabaseMissing('tenant_users', [
        'tenant_id' => $tenant->id,
        'user_id' => $agent->id,
    ]);
});

test('ROLES-20: un admin no puede remover a un owner ni a otro admin (422)', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = User::factory()->create();
    $owner = User::factory()->create();
    $otherAdmin = User::factory()->create();
    make_tenant_member($admin, $tenant, 'admin');
    make_tenant_member($owner, $tenant, 'owner');
    make_tenant_member($otherAdmin, $tenant, 'admin');

    $this->actingAs($admin)
        ->deleteJson('/api/v1/tenants/'.$tenant->id.'/users/'.$owner->id)
        ->assertStatus(422)
        ->assertJson(['code' => 'ROLE_CHANGE_NOT_ALLOWED']);

    $this->actingAs($admin)
        ->deleteJson('/api/v1/tenants/'.$tenant->id.'/users/'.$otherAdmin->id)
        ->assertStatus(422)
        ->assertJson(['code' => 'ROLE_CHANGE_NOT_ALLOWED']);
});
