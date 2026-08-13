<?php

declare(strict_types=1);

use App\Domain\Tenants\Exceptions\TenantContextMissingException;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\ScopedWidget;

uses(RefreshDatabase::class);

test('TEST 1: un tenant no obtiene los datos de otro', function (): void {
    create_scoped_widgets_table();
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    insert_scoped_widget($tenantA->id, 'widget-a');
    insert_scoped_widget($tenantB->id, 'widget-b');

    TenantContext::set($tenantA);

    $visible = ScopedWidget::query()->get();

    expect($visible)->toHaveCount(1)
        ->and($visible->first()->tenant_id)->toBe($tenantA->id)
        ->and($visible->pluck('name'))->not->toContain('widget-b');
});

test('TEST 2: un tenant no puede modificar datos de otro', function (): void {
    create_scoped_widgets_table();
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    insert_scoped_widget($tenantA->id, 'widget-a');
    insert_scoped_widget($tenantB->id, 'widget-b');

    $widgetBId = DB::table('scoped_widgets')->where('tenant_id', $tenantB->id)->value('id');

    TenantContext::set($tenantA);

    $affected = ScopedWidget::query()->whereKey($widgetBId)->update(['name' => 'hacked']);

    expect($affected)->toBe(0)
        ->and(ScopedWidget::withoutTenantScope()->find($widgetBId)?->name)->toBe('widget-b');
});

test('TEST 3: un tenant no puede eliminar datos de otro', function (): void {
    create_scoped_widgets_table();
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    insert_scoped_widget($tenantA->id, 'widget-a');
    insert_scoped_widget($tenantB->id, 'widget-b');

    $widgetBId = DB::table('scoped_widgets')->where('tenant_id', $tenantB->id)->value('id');

    TenantContext::set($tenantA);

    $deleted = ScopedWidget::query()->whereKey($widgetBId)->delete();

    expect($deleted)->toBe(0)
        ->and(ScopedWidget::withoutTenantScope()->find($widgetBId))->not->toBeNull();
});

test('TEST 6: las queries de un modelo tenant se filtran automáticamente por el contexto', function (): void {
    create_scoped_widgets_table();
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    insert_scoped_widget($tenantA->id, 'widget-a');
    insert_scoped_widget($tenantB->id, 'widget-b');

    TenantContext::set($tenantA);
    expect(ScopedWidget::query()->count())->toBe(1);

    TenantContext::set($tenantB);
    expect(ScopedWidget::query()->count())->toBe(1);

    TenantContext::set($tenantA);
    ScopedWidget::query()->create(['name' => 'widget-a2']);
    expect(ScopedWidget::query()->count())->toBe(2);
});

test('TEST 7: los registros creados reciben el tenant_id del contexto activo', function (): void {
    create_scoped_widgets_table();
    $tenantA = Tenant::factory()->create();

    TenantContext::set($tenantA);

    $widget = ScopedWidget::query()->create(['name' => 'nuevo']);

    expect($widget->tenant_id)->toBe($tenantA->id)
        ->and(ScopedWidget::query()->find($widget->id)?->tenant_id)->toBe($tenantA->id);
});

test('TEST 14: el tenant activo debe pertenecer al usuario; si no, se deniega y no queda contexto colgado', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $user = User::factory()->create();
    $user->tenants()->attach($tenantA, ['role' => 'owner']);
    $user->forceFill(['current_tenant_id' => $tenantB->id])->save();

    expect($user->isCurrentTenant($tenantB))->toBeFalse();

    $this->actingAs($user)
        ->getJson('/api/v1/tenants/'.$tenantA->id)
        ->assertStatus(403)
        ->assertJson(['code' => 'NO_TENANT']);

    expect(TenantContext::id())->toBeNull();
});

test('TEST 15: un tenant inexistente devuelve 404 sin revelar información', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/tenants/'.Str::uuid()->toString())
        ->assertStatus(404);

    $this->actingAs($user)
        ->postJson('/api/v1/tenants/'.Str::uuid()->toString().'/switch')
        ->assertStatus(404);
});

test('TEST 16: sin TenantContext las lecturas no devuelven nada y las escrituras fallan de forma segura', function (): void {
    create_scoped_widgets_table();
    $tenant = Tenant::factory()->create();

    insert_scoped_widget($tenant->id, 'widget');

    TenantContext::clear();

    expect(ScopedWidget::query()->count())->toBe(0);

    TenantContext::clear();

    expect(fn () => ScopedWidget::query()->create(['name' => 'sin-contexto']))
        ->toThrow(TenantContextMissingException::class);
});

test('un usuario sin tenant activo recibe 403 NO_TENANT en los recursos del tenant', function (): void {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/tenants/'.$tenant->id)
        ->assertStatus(403)
        ->assertJson(['code' => 'NO_TENANT']);
});

test('el middleware limpia el contexto tras atender la petición', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    $user->tenants()->attach($tenant, ['role' => 'owner']);
    $user->forceFill(['current_tenant_id' => $tenant->id])->save();

    $this->actingAs($user)
        ->getJson('/api/v1/tenants/'.$tenant->id)
        ->assertOk();

    expect(TenantContext::id())->toBeNull();
});

test('los recursos del tenant requieren autenticación', function (): void {
    $this->getJson('/api/v1/tenants')
        ->assertStatus(401)
        ->assertJson(['code' => 'UNAUTHENTICATED']);
});
