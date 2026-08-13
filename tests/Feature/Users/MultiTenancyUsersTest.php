<?php

declare(strict_types=1);

use App\Application\Users\Services\InvitationService;
use App\Application\Users\Services\TenantRoleManager;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| MULTI-TENANCY 21-24: aislamiento absoluto de usuarios por tenant
|--------------------------------------------------------------------------
*/

test('MT-21: las invitaciones son privadas del tenant (no visibles ni revocables por otro)', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    make_tenant_member($ownerA, $tenantA, 'owner');
    make_tenant_member($ownerB, $tenantB, 'owner');

    $invitationB = app(InvitationService::class)->invite($ownerB, $tenantB, 'b@example.com', UserRole::Agent);

    // El listado de A no contiene invitaciones de B.
    $this->actingAs($ownerA)
        ->getJson('/api/v1/tenants/'.$tenantA->id.'/users/invitations')
        ->assertOk()
        ->assertJsonCount(0, 'invitations')
        ->assertJsonMissing(['id' => $invitationB->id]);

    // Revocar la invitación de B desde A es 404 (no revela su existencia).
    $this->actingAs($ownerA)
        ->postJson('/api/v1/tenants/'.$tenantA->id.'/users/invitations/'.$invitationB->id.'/revoke')
        ->assertStatus(404);

    $this->assertDatabaseHas('tenant_invitations', [
        'id' => $invitationB->id,
        'status' => 'pending',
    ]);
});

test('MT-22: los permisos dependen del rol en el tenant ACTIVO, no del usuario', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $user = User::factory()->create();
    make_tenant_member($user, $tenantA, 'agent');
    $user->tenants()->attach($tenantB, ['role' => 'admin', 'status' => 'active', 'joined_at' => now()]);

    // Como agent en A: sin acceso a usuarios.
    $user->forceFill(['current_tenant_id' => $tenantA->id])->save();
    $this->actingAs($user)
        ->getJson('/api/v1/tenants/'.$tenantA->id.'/users')
        ->assertStatus(403)
        ->assertJson(['code' => 'PERMISSION_DENIED']);

    // Como admin en B: con acceso.
    $user->forceFill(['current_tenant_id' => $tenantB->id])->save();
    $this->actingAs($user)
        ->getJson('/api/v1/tenants/'.$tenantB->id.'/users')
        ->assertOk();

    // me() reporta el rol del tenant activo.
    $this->actingAs($user)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('current_role', 'admin')
        ->assertJsonPath('current_tenant_id', $tenantB->id);
});

test('MT-23: aceptar una invitación a B no da acceso a B sin switch previo', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $ownerB = User::factory()->create();
    make_tenant_member($ownerB, $tenantB, 'owner');

    $user = User::factory()->create(['email' => 'invited@example.com']);
    make_tenant_member($user, $tenantA, 'owner');

    $token = invitation_token(fn () => app(InvitationService::class)->invite($ownerB, $tenantB, 'invited@example.com', UserRole::Admin));

    $this->actingAs($user)
        ->postJson('/api/v1/invitations/'.$token.'/accept')
        ->assertOk();

    // El tenant activo sigue siendo A; B aún no es accesible bajo contexto
    // (404: no se revela que B existe ni la membresía recién aceptada).
    $this->actingAs($user)
        ->getJson('/api/v1/tenants/'.$tenantB->id.'/users')
        ->assertStatus(404);

    // Tras el switch, la membresía aceptada ya es operativa.
    $this->actingAs($user)
        ->postJson('/api/v1/tenants/'.$tenantB->id.'/switch')
        ->assertOk();

    $this->actingAs($user)
        ->getJson('/api/v1/tenants/'.$tenantB->id.'/users')
        ->assertOk();
});

test('MT-24: un super_admin de plataforma no gestiona usuarios de tenants', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $tenantA = Tenant::factory()->create();
    $admin = User::factory()->create();
    app(TenantRoleManager::class)->assignGlobalRole($admin, UserRole::SuperAdmin);

    // Sin membresías ni tenant activo: la ruta tenant devuelve NO_TENANT.
    $this->actingAs($admin)
        ->getJson('/api/v1/tenants/'.$tenantA->id.'/users')
        ->assertStatus(403)
        ->assertJson(['code' => 'NO_TENANT']);

    $this->actingAs($admin)
        ->postJson('/api/v1/tenants/'.$tenantA->id.'/users/invitations', [
            'email' => 'x@example.com',
            'role' => 'agent',
        ])
        ->assertStatus(403)
        ->assertJson(['code' => 'NO_TENANT']);
});

test('CRITICO: un miembro ADMIN en A y AGENT en B pierde el acceso a usuarios al cambiar de tenant', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $user = User::factory()->create();
    make_tenant_member($user, $tenantA, 'admin');
    $user->tenants()->attach($tenantB, ['role' => 'agent', 'status' => 'active', 'joined_at' => now()]);

    // En A (admin): gestiona usuarios.
    $user->forceFill(['current_tenant_id' => $tenantA->id])->save();
    $this->actingAs($user)
        ->getJson('/api/v1/tenants/'.$tenantA->id.'/users')
        ->assertOk();

    // En B (agent): 403 al instante. La autorización es por tenant activo.
    $user->forceFill(['current_tenant_id' => $tenantB->id])->save();
    $this->actingAs($user)
        ->getJson('/api/v1/tenants/'.$tenantB->id.'/users')
        ->assertStatus(403)
        ->assertJson(['code' => 'PERMISSION_DENIED']);

    // Volver a A restaura el acceso.
    $user->forceFill(['current_tenant_id' => $tenantA->id])->save();
    $this->actingAs($user)
        ->getJson('/api/v1/tenants/'.$tenantA->id.'/users')
        ->assertOk();
});
