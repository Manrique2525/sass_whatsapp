<?php

declare(strict_types=1);

use App\Application\Tenants\Services\SwitchTenant;
use App\Application\Tenants\Services\TenantService;
use App\Domain\Tenants\Exceptions\TenantMembershipException;
use App\Domain\Tenants\Exceptions\TenantNotActiveException;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('SwitchTenant cambia el tenant activo de un miembro y registra auditoría', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    $user->tenants()->attach($tenant, ['role' => 'owner']);

    app(SwitchTenant::class)->execute($user, $tenant);

    expect($user->fresh()->current_tenant_id)->toBe($tenant->id)
        ->and(DB::table('audit_logs')->where('action', 'tenant.switched')->count())->toBe(1);
});

test('SwitchTenant lanza TenantMembershipException si el usuario no es miembro', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();

    app(SwitchTenant::class)->execute($user, $tenant);
})->throws(TenantMembershipException::class);

test('SwitchTenant lanza TenantNotActiveException ante un tenant suspendido', function (): void {
    $tenant = Tenant::factory()->suspended()->create();
    $user = User::factory()->create();
    $user->tenants()->attach($tenant, ['role' => 'owner']);

    app(SwitchTenant::class)->execute($user, $tenant);
})->throws(TenantNotActiveException::class);

test('TenantService::currentForUser devuelve null si el tenant activo no es miembro', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    $user->forceFill(['current_tenant_id' => $tenant->id])->save();

    expect(app(TenantService::class)->currentForUser($user))->toBeNull();
});

test('TenantService::currentForUser devuelve el tenant activo de un miembro', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    $user->tenants()->attach($tenant, ['role' => 'owner']);
    $user->forceFill(['current_tenant_id' => $tenant->id])->save();

    expect(app(TenantService::class)->currentForUser($user)?->id)->toBe($tenant->id);
});

test('TenantService::showForUser rechaza un tenant que no es el activo', function (): void {
    $current = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $user = User::factory()->create();
    $user->tenants()->attach($current, ['role' => 'owner']);
    $user->tenants()->attach($other, ['role' => 'agent']);
    $user->forceFill(['current_tenant_id' => $current->id])->save();

    app(TenantService::class)->showForUser($user, $other);
})->throws(TenantMembershipException::class);
