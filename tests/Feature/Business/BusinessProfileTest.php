<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Business\Models\BusinessProfile;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\TenantPermission;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| FASE 5 — BUSINESS PROFILE
|--------------------------------------------------------------------------
*/

function bp_url(Tenant $tenant): string
{
    return '/api/v1/tenants/'.$tenant->id.'/business-profile';
}

test('BP-1: GET del perfil devuelve el del tenant activo y lo crea si no existe', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $this->actingAs($owner)
        ->getJson(bp_url($tenant))
        ->assertOk()
        ->assertJsonPath('business_profile.name', null);

    $this->assertDatabaseHas('business_profiles', [
        'tenant_id' => $tenant->id,
    ]);
});

test('BP-2: PUT actualiza todos los campos del perfil', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $payload = [
        'name' => 'Panadería Central',
        'description' => 'Pan artesanal y pastelería.',
        'category' => 'gastronomia',
        'address' => 'Av. Siempre Viva 123',
        'website' => 'https://panaderia.example.com',
        'email' => 'hola@panaderia.example.com',
        'phone' => '+54 11 5555 4444',
        'working_hours' => [
            ['day' => 'mon', 'open' => '08:00', 'close' => '20:00', 'closed' => false],
            ['day' => 'sun', 'open' => null, 'close' => null, 'closed' => true],
        ],
    ];

    $this->actingAs($owner)
        ->putJson(bp_url($tenant), $payload)
        ->assertOk()
        ->assertJsonPath('business_profile.name', 'Panadería Central')
        ->assertJsonPath('business_profile.email', 'hola@panaderia.example.com')
        ->assertJsonPath('business_profile.working_hours.0.day', 'mon');

    $this->assertDatabaseHas('business_profiles', [
        'tenant_id' => $tenant->id,
        'name' => 'Panadería Central',
        'category' => 'gastronomia',
        'email' => 'hola@panaderia.example.com',
    ]);
});

test('BP-3: PUT parcial actualiza solo los campos enviados', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $this->actingAs($owner)
        ->putJson(bp_url($tenant), ['name' => 'Solo nombre'])
        ->assertOk()
        ->assertJsonPath('business_profile.name', 'Solo nombre')
        ->assertJsonPath('business_profile.email', null);

    $this->assertDatabaseHas('business_profiles', [
        'tenant_id' => $tenant->id,
        'name' => 'Solo nombre',
    ]);
});

test('BP-4: PUT valida email, website, phone y working_hours', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $this->actingAs($owner)
        ->putJson(bp_url($tenant), ['email' => 'no-es-un-email'])
        ->assertStatus(422);

    $this->actingAs($owner)
        ->putJson(bp_url($tenant), ['website' => 'no-es-una-url'])
        ->assertStatus(422);

    $this->actingAs($owner)
        ->putJson(bp_url($tenant), ['phone' => str_repeat('1', 41)])
        ->assertStatus(422);

    $this->actingAs($owner)
        ->putJson(bp_url($tenant), ['working_hours' => [['day' => 'lunes']]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('working_hours.0.day');

    $this->actingAs($owner)
        ->putJson(bp_url($tenant), ['working_hours' => [['day' => 'mon', 'open' => '25:00', 'close' => '20:00']]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('working_hours.0.open');
});

test('BP-5: el agente puede leer pero NO actualizar el perfil', function (): void {
    $tenant = Tenant::factory()->create();
    $agent = User::factory()->create();
    make_tenant_member($agent, $tenant, 'agent');

    $this->actingAs($agent)
        ->getJson(bp_url($tenant))
        ->assertOk();

    $this->actingAs($agent)
        ->putJson(bp_url($tenant), ['name' => 'Intento de agente'])
        ->assertStatus(403)
        ->assertJson(['code' => 'PERMISSION_DENIED']);

    $this->assertDatabaseMissing('business_profiles', [
        'tenant_id' => $tenant->id,
        'name' => 'Intento de agente',
    ]);
});

test('CRITICO BP-6: Tenant A jamás lee ni modifica el perfil de Tenant B', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    make_tenant_member($ownerA, $tenantA, 'owner');
    make_tenant_member($ownerB, $tenantB, 'owner');

    $profileBId = (string) Str::uuid();
    DB::table('business_profiles')->insert([
        'id' => $profileBId,
        'tenant_id' => $tenantB->id,
        'name' => 'Negocio de B',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // A no ve el perfil de B: 404 (no se revela su existencia).
    $this->actingAs($ownerA)
        ->getJson(bp_url($tenantB))
        ->assertStatus(404);

    // A no puede modificar el perfil de B: 404.
    $this->actingAs($ownerA)
        ->putJson(bp_url($tenantB), ['name' => 'Hackeado'])
        ->assertStatus(404);

    // El perfil de B sigue intacto y sin filas creadas para A.
    $this->assertDatabaseHas('business_profiles', [
        'id' => $profileBId,
        'name' => 'Negocio de B',
    ]);
});

test('BP-7: solo el tenant ACTIVO es accesible; otro tenant requiere switch previo', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $user = User::factory()->create();
    make_tenant_member($user, $tenantA, 'owner');
    $user->tenants()->attach($tenantB, ['role' => 'owner', 'status' => 'active', 'joined_at' => now()]);

    $user->forceFill(['current_tenant_id' => $tenantA->id])->save();
    $this->actingAs($user)
        ->getJson(bp_url($tenantB))
        ->assertStatus(404);

    $user->forceFill(['current_tenant_id' => $tenantB->id])->save();
    $this->actingAs($user)
        ->getJson(bp_url($tenantB))
        ->assertOk();
});

test('BP-8: un tenant_id enviado en el cuerpo es ignorado', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $owner = User::factory()->create();
    make_tenant_member($owner, $tenantA, 'owner');

    $this->actingAs($owner)
        ->putJson(bp_url($tenantA), [
            'name' => 'Con spoofing',
            'tenant_id' => $tenantB->id,
        ])
        ->assertOk();

    // El perfil se crea bajo A (TenantContext), jamás bajo B.
    $this->assertDatabaseHas('business_profiles', [
        'tenant_id' => $tenantA->id,
        'name' => 'Con spoofing',
    ]);
    $this->assertDatabaseMissing('business_profiles', [
        'tenant_id' => $tenantB->id,
    ]);
});

test('BP-9: los cambios y la creación quedan auditados', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $this->actingAs($owner)
        ->getJson(bp_url($tenant))
        ->assertOk();

    $profileId = DB::table('business_profiles')->where('tenant_id', $tenant->id)->value('id');

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'business_profile.created',
        'tenant_id' => $tenant->id,
        'actor_user_id' => $owner->id,
        'subject_type' => BusinessProfile::class,
        'subject_id' => $profileId,
    ]);

    $this->actingAs($owner)
        ->putJson(bp_url($tenant), ['name' => 'Auditado'])
        ->assertOk();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'business_profile.updated',
        'tenant_id' => $tenant->id,
        'actor_user_id' => $owner->id,
        'subject_id' => $profileId,
    ]);

    $audit = AuditLog::query()
        ->where('action', 'business_profile.updated')
        ->firstOrFail();

    expect($audit->data['changed'])->toContain('name');
});

test('BP-10: un no-miembro del tenant recibe 404', function (): void {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $stranger = User::factory()->create();
    make_tenant_member($stranger, $other, 'owner');

    $this->actingAs($stranger)
        ->getJson(bp_url($tenant))
        ->assertStatus(404);
});

test('BP-11: con tenant suspendido como activo, el acceso es denegado', function (): void {
    $tenant = Tenant::factory()->suspended()->create();
    $owner = User::factory()->create();
    $owner->tenants()->attach($tenant, ['role' => 'owner', 'status' => 'active', 'joined_at' => now()]);
    $owner->forceFill(['current_tenant_id' => $tenant->id])->save();

    $this->actingAs($owner)
        ->getJson(bp_url($tenant))
        ->assertStatus(403)
        ->assertJson(['code' => 'NO_TENANT']);
});

test('BP-12: la matriz de permisos concede view a todos y update solo a owner/admin', function (): void {
    $ownerPerms = TenantPermission::permissionsForRole(UserRole::Owner);
    $adminPerms = TenantPermission::permissionsForRole(UserRole::Admin);
    $agentPerms = TenantPermission::permissionsForRole(UserRole::Agent);

    expect($ownerPerms)->toContain(TenantPermission::ViewBusinessProfile)
        ->and($ownerPerms)->toContain(TenantPermission::UpdateBusinessProfile)
        ->and($adminPerms)->toContain(TenantPermission::ViewBusinessProfile)
        ->and($adminPerms)->toContain(TenantPermission::UpdateBusinessProfile)
        ->and($agentPerms)->toContain(TenantPermission::ViewBusinessProfile)
        ->and($agentPerms)->not->toContain(TenantPermission::UpdateBusinessProfile);
});
