<?php

declare(strict_types=1);

use App\Application\Users\Services\AuthorizationService;
use App\Domain\Tenants\Exceptions\PermissionDeniedException;
use App\Domain\Tenants\Exceptions\TenantMembershipException;
use App\Domain\Tenants\Exceptions\TenantNotActiveException;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\TenantPermission;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
});

/*
|--------------------------------------------------------------------------
| AuthorizationService Tests (FASE 29 U2)
|--------------------------------------------------------------------------
|
| F29-U2-AUTHZ-01..16 — Pipeline: tenant active → membership → role → permission.
| Covers: Owner/Admin/Agent permissions, can(), permissionsForTenant().
| No policy duplication — U1 already covers direct policy tests.
|
*/

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->otherTenant = Tenant::factory()->create();

    $this->owner = User::factory()->create();
    $this->admin = User::factory()->create();
    $this->agent = User::factory()->create();
    $this->outsider = User::factory()->create();

    make_tenant_member($this->owner, $this->tenant, 'owner');
    make_tenant_member($this->admin, $this->tenant, 'admin');
    make_tenant_member($this->agent, $this->tenant, 'agent');
    make_tenant_member($this->outsider, $this->otherTenant, 'owner');

    $this->owner->forceFill(['current_tenant_id' => $this->tenant->id])->save();
    $this->admin->forceFill(['current_tenant_id' => $this->tenant->id])->save();
    $this->agent->forceFill(['current_tenant_id' => $this->tenant->id])->save();
    $this->outsider->forceFill(['current_tenant_id' => $this->otherTenant->id])->save();

    $this->service = app(AuthorizationService::class);
});

it('F29-U2-AUTHZ-01: owner has all permissions', function (): void {
    foreach (TenantPermission::all() as $permission) {
        $this->service->authorize($this->owner, $permission, $this->tenant);
    }
    expect(true)->toBeTrue();
})->group('F29-U2-AUTHZ');

it('F29-U2-AUTHZ-02: admin cannot assign roles (AssignRoles)', function (): void {
    $this->service->authorize($this->admin, TenantPermission::AssignRoles, $this->tenant);
})->throws(PermissionDeniedException::class)->group('F29-U2-AUTHZ');

it('F29-U2-AUTHZ-03: admin cannot manage billing (ManageBilling)', function (): void {
    $this->service->authorize($this->admin, TenantPermission::ManageBilling, $this->tenant);
})->throws(PermissionDeniedException::class)->group('F29-U2-AUTHZ');

it('F29-U2-AUTHZ-04: admin has ViewUsers', function (): void {
    $this->service->authorize($this->admin, TenantPermission::ViewUsers, $this->tenant);
    expect(true)->toBeTrue();
})->group('F29-U2-AUTHZ');

it('F29-U2-AUTHZ-05: admin has ManageContacts', function (): void {
    $this->service->authorize($this->admin, TenantPermission::ManageContacts, $this->tenant);
    expect(true)->toBeTrue();
})->group('F29-U2-AUTHZ');

it('F29-U2-AUTHZ-06: agent has ViewConversations', function (): void {
    $this->service->authorize($this->agent, TenantPermission::ViewConversations, $this->tenant);
    expect(true)->toBeTrue();
})->group('F29-U2-AUTHZ');

it('F29-U2-AUTHZ-07: agent cannot ManageContacts', function (): void {
    $this->service->authorize($this->agent, TenantPermission::ManageContacts, $this->tenant);
})->throws(PermissionDeniedException::class)->group('F29-U2-AUTHZ');

it('F29-U2-AUTHZ-08: agent cannot InviteUsers', function (): void {
    $this->service->authorize($this->agent, TenantPermission::InviteUsers, $this->tenant);
})->throws(PermissionDeniedException::class)->group('F29-U2-AUTHZ');

it('F29-U2-AUTHZ-09: agent cannot ManageBilling', function (): void {
    $this->service->authorize($this->agent, TenantPermission::ManageBilling, $this->tenant);
})->throws(PermissionDeniedException::class)->group('F29-U2-AUTHZ');

it('F29-U2-AUTHZ-10: can() returns true for owner', function (): void {
    expect($this->service->can($this->owner, TenantPermission::ManageBilling, $this->tenant))->toBeTrue();
})->group('F29-U2-AUTHZ');

it('F29-U2-AUTHZ-11: can() returns false for agent without permission', function (): void {
    expect($this->service->can($this->agent, TenantPermission::ManageContacts, $this->tenant))->toBeFalse();
})->group('F29-U2-AUTHZ');

it('F29-U2-AUTHZ-12: can() returns false for outsider (no membership)', function (): void {
    expect($this->service->can($this->outsider, TenantPermission::ViewUsers, $this->tenant))->toBeFalse();
})->group('F29-U2-AUTHZ');

it('F29-U2-AUTHZ-13: inactive tenant throws TenantNotActiveException', function (): void {
    $this->tenant->forceFill(['status' => 'suspended'])->save();
    $this->service->authorize($this->owner, TenantPermission::ViewUsers, $this->tenant);
})->throws(TenantNotActiveException::class)->group('F29-U2-AUTHZ');

it('F29-U2-AUTHZ-14: cross-tenant user throws TenantMembershipException', function (): void {
    $this->outsider->forceFill(['current_tenant_id' => $this->otherTenant->id])->save();
    $this->service->authorize($this->outsider, TenantPermission::ViewUsers, $this->tenant);
})->throws(TenantMembershipException::class)->group('F29-U2-AUTHZ');

it('F29-U2-AUTHZ-15: permissionsForTenant returns all for owner', function (): void {
    $perms = $this->service->permissionsForTenant($this->owner, $this->tenant);
    expect($perms)->toHaveCount(count(TenantPermission::all()));
})->group('F29-U2-AUTHZ');

it('F29-U2-AUTHZ-16: permissionsForTenant returns empty for inactive tenant', function (): void {
    $this->tenant->forceFill(['status' => 'suspended'])->save();
    $perms = $this->service->permissionsForTenant($this->owner, $this->tenant);
    expect($perms)->toBeEmpty();
})->group('F29-U2-AUTHZ');
