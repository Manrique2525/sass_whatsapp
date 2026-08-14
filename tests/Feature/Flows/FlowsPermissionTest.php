<?php

declare(strict_types=1);

use App\Domain\Users\Enums\TenantPermission;
use App\Domain\Users\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| FLOW-20/21: matriz de permisos del dominio flows (FASE 11)
|--------------------------------------------------------------------------
*/

test('FLOW-20: la matriz concede flows.view a todos y flows.manage solo a owner/admin', function (): void {
    $ownerPerms = TenantPermission::permissionsForRole(UserRole::Owner);
    $adminPerms = TenantPermission::permissionsForRole(UserRole::Admin);
    $agentPerms = TenantPermission::permissionsForRole(UserRole::Agent);

    expect($ownerPerms)->toContain(TenantPermission::ViewFlows)
        ->and($ownerPerms)->toContain(TenantPermission::ManageFlows)
        ->and($adminPerms)->toContain(TenantPermission::ViewFlows)
        ->and($adminPerms)->toContain(TenantPermission::ManageFlows)
        ->and($agentPerms)->toContain(TenantPermission::ViewFlows)
        ->and($agentPerms)->not->toContain(TenantPermission::ManageFlows);
});

test('FLOW-21: todos los permisos de la matriz pertenecen a la lista oficial', function (): void {
    $official = array_map(
        static fn (TenantPermission $permission): string => $permission->value,
        TenantPermission::all(),
    );

    foreach (UserRole::cases() as $role) {
        foreach (TenantPermission::permissionsForRole($role) as $permission) {
            expect($official)->toContain($permission->value);
        }
    }
});
