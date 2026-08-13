<?php

declare(strict_types=1);

use App\Domain\Tenants\Enums\TenantStatus;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('TEST 8: el tenant_id enviado en el request es ignorado', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $user = User::factory()->create();
    $user->tenants()->attach($tenantA, ['role' => 'owner']);
    $user->tenants()->attach($tenantB, ['role' => 'owner']);
    $user->forceFill(['current_tenant_id' => $tenantA->id])->save();

    $this->actingAs($user)
        ->putJson('/api/v1/tenants/'.$tenantA->id, [
            'name' => 'Tenant A Renombrado',
            'timezone' => 'UTC',
            'locale' => 'en',
            'tenant_id' => $tenantB->id,
            'status' => 'suspended',
        ])
        ->assertOk()
        ->assertJsonPath('tenant.name', 'Tenant A Renombrado');

    // Solo name/timezone/locale se aplican; tenant_id y status del body se ignoran.
    expect($tenantA->fresh()->status)->toBe(TenantStatus::Active)
        ->and($tenantB->fresh()->name)->not->toBe('Tenant A Renombrado')
        ->and($tenantA->fresh()->tenant_id)->toBeNull()
        ->and($user->fresh()->current_tenant_id)->toBe($tenantA->id);
});

test('el switch ignora un tenant_id alternativo en el body', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $user = User::factory()->create();
    $user->tenants()->attach($tenantA, ['role' => 'owner']);
    $user->tenants()->attach($tenantB, ['role' => 'owner']);

    // La ruta resuelve el tenant destino por URL, no por body.
    $this->actingAs($user)
        ->postJson('/api/v1/tenants/'.$tenantA->id.'/switch', ['tenant_id' => $tenantB->id])
        ->assertOk()
        ->assertJsonPath('current_tenant_id', $tenantA->id);

    expect($user->fresh()->current_tenant_id)->toBe($tenantA->id);
});
