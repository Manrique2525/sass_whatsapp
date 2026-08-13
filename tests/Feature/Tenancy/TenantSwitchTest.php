<?php

declare(strict_types=1);

use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('TEST 4: un usuario no puede cambiar a un tenant del que no es miembro', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();

    // 404 (no 403): no se revela la existencia del tenant (ADR-010).
    $this->actingAs($user)
        ->postJson('/api/v1/tenants/'.$tenant->id.'/switch')
        ->assertStatus(404);

    expect($user->fresh()->current_tenant_id)->toBeNull();
});

test('TEST 5: un usuario miembro de A y B cambia correctamente entre ambos', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $user = User::factory()->create();
    $user->tenants()->attach($tenantA, ['role' => 'owner']);
    $user->tenants()->attach($tenantB, ['role' => 'admin']);

    $this->actingAs($user)
        ->postJson('/api/v1/tenants/'.$tenantA->id.'/switch')
        ->assertOk()
        ->assertJsonPath('current_tenant_id', $tenantA->id);

    expect($user->fresh()->current_tenant_id)->toBe($tenantA->id);

    $this->actingAs($user)
        ->postJson('/api/v1/tenants/'.$tenantB->id.'/switch')
        ->assertOk()
        ->assertJsonPath('current_tenant_id', $tenantB->id);

    expect($user->fresh()->current_tenant_id)->toBe($tenantB->id)
        ->and(TenantContext::id())->toBeNull();
});

test('TEST 13: el switch persiste el tenant activo en current_tenant_id', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    $user->tenants()->attach($tenant, ['role' => 'owner']);

    $this->actingAs($user)
        ->postJson('/api/v1/tenants/'.$tenant->id.'/switch')
        ->assertOk();

    expect($user->fresh()->current_tenant_id)->toBe($tenant->id);
});

test('el switch sobre un tenant suspendido es rechazado', function (): void {
    $tenant = Tenant::factory()->suspended()->create();
    $user = User::factory()->create();
    $user->tenants()->attach($tenant, ['role' => 'owner']);

    // El miembro conoce su propio tenant suspendido: 409, no 404 (ADR-023).
    $this->actingAs($user)
        ->postJson('/api/v1/tenants/'.$tenant->id.'/switch')
        ->assertStatus(409)
        ->assertJson(['code' => 'TENANT_NOT_ACTIVE']);

    expect($user->fresh()->current_tenant_id)->toBeNull();
});

test('GET /api/v1/tenants lista solo los tenants activos del usuario', function (): void {
    $active = Tenant::factory()->create();
    $suspended = Tenant::factory()->suspended()->create();
    $other = Tenant::factory()->create();
    $user = User::factory()->create();
    $user->tenants()->attach($active, ['role' => 'owner']);
    $user->tenants()->attach($suspended, ['role' => 'agent']);

    $this->actingAs($user)
        ->getJson('/api/v1/tenants')
        ->assertOk()
        ->assertJsonCount(1, 'tenants')
        ->assertJsonFragment(['id' => $active->id])
        ->assertJsonMissing(['id' => $other->id])
        ->assertJsonPath('current_tenant_id', null);
});

test('GET /api/v1/tenants/{id} solo expone el tenant activo del usuario', function (): void {
    $current = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $user = User::factory()->create();
    $user->tenants()->attach($current, ['role' => 'owner']);
    $user->tenants()->attach($other, ['role' => 'agent']);
    $user->forceFill(['current_tenant_id' => $current->id])->save();

    $this->actingAs($user)
        ->getJson('/api/v1/tenants/'.$current->id)
        ->assertOk()
        ->assertJsonPath('tenant.id', $current->id);

    // Otro tenant del mismo usuario NO es visible sin switch previo (404, no revela).
    $this->actingAs($user)
        ->getJson('/api/v1/tenants/'.$other->id)
        ->assertStatus(404);
});

test('PUT /api/v1/tenants/{id} actualiza el tenant activo y registra auditoría', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    $user->tenants()->attach($tenant, ['role' => 'owner']);
    $user->forceFill(['current_tenant_id' => $tenant->id])->save();

    $this->actingAs($user)
        ->putJson('/api/v1/tenants/'.$tenant->id, [
            'name' => 'Mi Empresa S.A.',
            'timezone' => 'America/Mexico_City',
            'locale' => 'es',
        ])
        ->assertOk()
        ->assertJsonPath('tenant.name', 'Mi Empresa S.A.')
        ->assertJsonPath('tenant.timezone', 'America/Mexico_City')
        ->assertJsonPath('tenant.locale', 'es');

    expect($tenant->fresh()->name)->toBe('Mi Empresa S.A.')
        ->and(DB::table('audit_logs')->where('action', 'tenant.updated')->count())->toBe(1);
});

test('PUT /api/v1/tenants/{id} valida los datos del request', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    $user->tenants()->attach($tenant, ['role' => 'owner']);
    $user->forceFill(['current_tenant_id' => $tenant->id])->save();

    $this->actingAs($user)
        ->putJson('/api/v1/tenants/'.$tenant->id, [
            'name' => '',
            'timezone' => 'NoEs/Zona',
            'locale' => 'xx',
        ])
        ->assertStatus(422)
        ->assertJson(['code' => 'VALIDATION_ERROR']);
});

test('el switch registra una entrada de auditoría', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    $user->tenants()->attach($tenant, ['role' => 'owner']);

    $this->actingAs($user)
        ->postJson('/api/v1/tenants/'.$tenant->id.'/switch')
        ->assertOk();

    expect(DB::table('audit_logs')->where('action', 'tenant.switched')->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('GET /api/v1/auth/me expone los tenants con su rol y el tenant activo', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $user = User::factory()->create();
    $user->tenants()->attach($tenantA, ['role' => 'owner']);
    $user->tenants()->attach($tenantB, ['role' => 'agent']);
    $user->forceFill(['current_tenant_id' => $tenantA->id])->save();

    $token = $user->createToken('api')->plainTextToken;

    $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonCount(2, 'tenants')
        ->assertJsonPath('current_tenant_id', $tenantA->id)
        ->assertJsonPath('current_tenant.id', $tenantA->id)
        ->assertJsonFragment(['id' => $tenantA->id, 'role' => 'owner'])
        ->assertJsonFragment(['id' => $tenantB->id, 'role' => 'agent']);
});
