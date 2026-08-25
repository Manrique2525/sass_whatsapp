<?php

declare(strict_types=1);

use App\Domain\Billing\Contracts\CapacityGuardInterface;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Fakes\FakeCapacityGuard;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(CapacityGuardInterface::class, new FakeCapacityGuard);
});

/*
|--------------------------------------------------------------------------
| AUTHORIZATION 1-8: control de acceso por rol/permiso en usuarios del tenant
|--------------------------------------------------------------------------
*/

test('AUTH-1: un usuario sin autenticar no accede a miembros ni invitaciones (401)', function (): void {
    $tenant = Tenant::factory()->create();

    $this->getJson('/api/v1/tenants/'.$tenant->id.'/users')
        ->assertStatus(401);

    $this->postJson('/api/v1/tenants/'.$tenant->id.'/users/invitations', [
        'email' => 'a@example.com',
        'role' => 'agent',
    ])->assertStatus(401);
});

test('AUTH-2: un agent no puede listar usuarios (403 PERMISSION_DENIED)', function (): void {
    $tenant = Tenant::factory()->create();
    $agent = User::factory()->create();
    make_tenant_member($agent, $tenant, 'agent');

    $this->actingAs($agent)
        ->getJson('/api/v1/tenants/'.$tenant->id.'/users')
        ->assertStatus(403)
        ->assertJson(['code' => 'PERMISSION_DENIED']);
});

test('AUTH-3: un agent no puede invitar (403)', function (): void {
    $tenant = Tenant::factory()->create();
    $agent = User::factory()->create();
    make_tenant_member($agent, $tenant, 'agent');

    $this->actingAs($agent)
        ->postJson('/api/v1/tenants/'.$tenant->id.'/users/invitations', [
            'email' => 'x@example.com',
            'role' => 'agent',
        ])
        ->assertStatus(403)
        ->assertJson(['code' => 'PERMISSION_DENIED']);
});

test('AUTH-4: un agent no puede cambiar el rol de un miembro (403)', function (): void {
    $tenant = Tenant::factory()->create();
    $agent = User::factory()->create();
    $target = User::factory()->create();
    make_tenant_member($agent, $tenant, 'agent');
    make_tenant_member($target, $tenant, 'agent');

    $this->actingAs($agent)
        ->patchJson('/api/v1/tenants/'.$tenant->id.'/users/'.$target->id, ['role' => 'admin'])
        ->assertStatus(403)
        ->assertJson(['code' => 'PERMISSION_DENIED']);
});

test('AUTH-5: un agent no puede remover a un miembro (403)', function (): void {
    $tenant = Tenant::factory()->create();
    $agent = User::factory()->create();
    $target = User::factory()->create();
    make_tenant_member($agent, $tenant, 'agent');
    make_tenant_member($target, $tenant, 'agent');

    $this->actingAs($agent)
        ->deleteJson('/api/v1/tenants/'.$tenant->id.'/users/'.$target->id)
        ->assertStatus(403)
        ->assertJson(['code' => 'PERMISSION_DENIED']);
});

test('AUTH-6: un admin puede ver miembros e invitaciones (200)', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    make_tenant_member($admin, $tenant, 'admin');
    make_tenant_member($member, $tenant, 'agent');

    $this->actingAs($admin)
        ->getJson('/api/v1/tenants/'.$tenant->id.'/users')
        ->assertOk()
        ->assertJsonCount(2, 'members');

    $this->actingAs($admin)
        ->getJson('/api/v1/tenants/'.$tenant->id.'/users/invitations')
        ->assertOk();
});

test('AUTH-7: un owner puede invitar, cambiar rol y remover (201/200/200)', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    Notification::fake();

    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    $agent = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');
    make_tenant_member($agent, $tenant, 'agent');

    $this->actingAs($owner)
        ->postJson('/api/v1/tenants/'.$tenant->id.'/users/invitations', [
            'email' => 'new@example.com',
            'role' => 'agent',
        ])
        ->assertStatus(201);

    $this->actingAs($owner)
        ->patchJson('/api/v1/tenants/'.$tenant->id.'/users/'.$agent->id, ['role' => 'admin'])
        ->assertOk();

    $this->actingAs($owner)
        ->deleteJson('/api/v1/tenants/'.$tenant->id.'/users/'.$agent->id)
        ->assertOk();
});

test('AUTH-8: un no-miembro del tenant recibe 404 (oculta la existencia)', function (): void {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $outsider = User::factory()->create();
    make_tenant_member($outsider, $other, 'owner');

    $this->actingAs($outsider)
        ->getJson('/api/v1/tenants/'.$tenant->id.'/users')
        ->assertStatus(404);

    $this->actingAs($outsider)
        ->postJson('/api/v1/tenants/'.$tenant->id.'/users/invitations', [
            'email' => 'x@example.com',
            'role' => 'agent',
        ])
        ->assertStatus(404);
});
