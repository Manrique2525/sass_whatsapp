<?php

declare(strict_types=1);

use App\Domain\Tenants\Models\Tenant;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FailingWidgetJob;
use Tests\Support\ScopedWidget;
use Tests\Support\WriteWidgetJob;

uses(RefreshDatabase::class);

test('TEST 9: un job tenant-aware establece su propio contexto de tenant', function (): void {
    create_scoped_widgets_table();
    $tenant = Tenant::factory()->create();

    dispatch((new WriteWidgetJob('desde-job'))->forTenant($tenant->id));

    expect(ScopedWidget::withoutTenantScope()->where('name', 'desde-job')->first()?->tenant_id)
        ->toBe($tenant->id);
});

test('TEST 10: un job de A no contamina el contexto de un job de B', function (): void {
    create_scoped_widgets_table();
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    dispatch((new WriteWidgetJob('job-a'))->forTenant($tenantA->id));
    dispatch((new WriteWidgetJob('job-b'))->forTenant($tenantB->id));

    $rows = DB::table('scoped_widgets')->orderBy('id')->get(['name', 'tenant_id']);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->tenant_id)->toBe($tenantA->id)
        ->and($rows[1]->tenant_id)->toBe($tenantB->id);
});

test('TEST 11: tras ejecutar un job el contexto queda limpio', function (): void {
    create_scoped_widgets_table();
    $tenant = Tenant::factory()->create();

    dispatch((new WriteWidgetJob('x'))->forTenant($tenant->id));

    expect(TenantContext::id())->toBeNull()
        ->and(TenantContext::bound())->toBeFalse();
});

test('un job usa su tenant_id propio y restaura el contexto previo al encolarse', function (): void {
    create_scoped_widgets_table();
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    TenantContext::set($tenantA);

    dispatch((new WriteWidgetJob('para-b'))->forTenant($tenantB->id));

    expect(ScopedWidget::withoutTenantScope()->where('name', 'para-b')->first()?->tenant_id)
        ->toBe($tenantB->id)
        ->and(TenantContext::id())->toBe($tenantA->id);
});

test('un job que lanza excepción libera el contexto en finally', function (): void {
    create_scoped_widgets_table();
    $tenant = Tenant::factory()->create();

    try {
        dispatch((new FailingWidgetJob)->forTenant($tenant->id));
    } catch (RuntimeException) {
        // esperado
    }

    expect(TenantContext::id())->toBeNull();
});
