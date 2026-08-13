<?php

declare(strict_types=1);

use App\Domain\Tenants\Exceptions\TenantContextMissingException;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\ScopedWidget;

uses(RefreshDatabase::class);

test('REG: un no-miembro recibe 404 en show/update/switch (sin oráculo de existencia)', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $user = User::factory()->create();
    $user->tenants()->attach($tenantB, ['role' => 'agent']);
    $user->forceFill(['current_tenant_id' => $tenantB->id])->save();

    // 404, nunca 403: no se revela que el tenant existe (ADR-010).
    $this->actingAs($user)
        ->getJson('/api/v1/tenants/'.$tenantA->id)
        ->assertStatus(404);

    $this->actingAs($user)
        ->putJson('/api/v1/tenants/'.$tenantA->id, [
            'name' => 'x',
            'timezone' => 'UTC',
            'locale' => 'en',
        ])
        ->assertStatus(404);

    $this->actingAs($user)
        ->postJson('/api/v1/tenants/'.$tenantA->id.'/switch')
        ->assertStatus(404);

    expect($user->fresh()->current_tenant_id)->toBe($tenantB->id);
});

test('REG: el contexto no se conserva entre requests HTTP del mismo proceso (A luego B)', function (): void {
    create_scoped_widgets_table();
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $userA = User::factory()->create();
    $userA->tenants()->attach($tenantA, ['role' => 'owner']);
    $userA->forceFill(['current_tenant_id' => $tenantA->id])->save();

    $userB = User::factory()->create();
    $userB->tenants()->attach($tenantB, ['role' => 'owner']);
    $userB->forceFill(['current_tenant_id' => $tenantB->id])->save();

    $this->actingAs($userA)
        ->getJson('/api/v1/tenants/'.$tenantA->id)
        ->assertOk();
    expect(TenantContext::id())->toBeNull();

    $this->actingAs($userB)
        ->getJson('/api/v1/tenants/'.$tenantB->id)
        ->assertOk();
    expect(TenantContext::id())->toBeNull();
});

test('REG: una excepción en el handler del middleware tenant libera el contexto', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $user = User::factory()->create();
    $user->tenants()->attach($tenantA, ['role' => 'owner']);
    $user->tenants()->attach($tenantB, ['role' => 'agent']);
    $user->forceFill(['current_tenant_id' => $tenantA->id])->save();

    // show de B (miembro pero no activo) -> TenantMembershipException -> 404;
    // la excepción atraviesa el finally del middleware que limpia el contexto.
    $this->actingAs($user)
        ->getJson('/api/v1/tenants/'.$tenantB->id)
        ->assertStatus(404);

    expect(TenantContext::id())->toBeNull();
});

test('REG: sin contexto las escrituras update/delete afectan 0 filas (fallo seguro)', function (): void {
    create_scoped_widgets_table();
    $tenant = Tenant::factory()->create();

    insert_scoped_widget($tenant->id, 'widget');

    TenantContext::clear();

    $widgetId = DB::table('scoped_widgets')->value('id');

    expect(ScopedWidget::query()->whereKey($widgetId)->update(['name' => 'sin-contexto']))->toBe(0);
    expect(ScopedWidget::withoutTenantScope()->find($widgetId)?->name)->toBe('widget');

    expect(ScopedWidget::query()->whereKey($widgetId)->delete())->toBe(0);
    expect(ScopedWidget::withoutTenantScope()->find($widgetId))->not->toBeNull();

    expect(fn () => ScopedWidget::query()->create(['name' => 'sin-contexto']))
        ->toThrow(TenantContextMissingException::class);
});

test('REG: un super_admin no accede a datos de tenants vía controllers normales', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $admin = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(0);
    $admin->assignRole('super_admin');

    // Sin membresías: el index solo muestra sus propios tenants (ninguno).
    $this->actingAs($admin)
        ->getJson('/api/v1/tenants')
        ->assertOk()
        ->assertJsonCount(0, 'tenants')
        ->assertJsonPath('current_tenant_id', null);

    // Sin tenant activo no opera bajo contexto: 403 NO_TENANT.
    $this->actingAs($admin)
        ->getJson('/api/v1/tenants/'.$tenantA->id)
        ->assertStatus(403)
        ->assertJson(['code' => 'NO_TENANT']);

    $this->actingAs($admin)
        ->putJson('/api/v1/tenants/'.$tenantA->id, [
            'name' => 'x',
            'timezone' => 'UTC',
            'locale' => 'en',
        ])
        ->assertStatus(403)
        ->assertJson(['code' => 'NO_TENANT']);

    // El switch exige membresía: 404.
    $this->actingAs($admin)
        ->postJson('/api/v1/tenants/'.$tenantA->id.'/switch')
        ->assertStatus(404);

    // Ni siquiera con una membresía propia ve los demás tenants. Se recarga la
    // instancia para evitar el cache de relaciones de requests anteriores.
    $admin->tenants()->attach($tenantA, ['role' => 'owner']);
    $admin->forceFill(['current_tenant_id' => $tenantA->id])->save();
    $admin = $admin->fresh();

    $this->actingAs($admin)
        ->getJson('/api/v1/tenants')
        ->assertOk()
        ->assertJsonCount(1, 'tenants')
        ->assertJsonFragment(['id' => $tenantA->id])
        ->assertJsonMissing(['id' => $tenantB->id]);

    $this->actingAs($admin)
        ->getJson('/api/v1/tenants/'.$tenantB->id)
        ->assertStatus(404);
});
