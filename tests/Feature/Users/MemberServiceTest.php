<?php

declare(strict_types=1);

use App\Application\Users\Services\MemberService;
use App\Domain\Tenants\Exceptions\PermissionDeniedException;
use App\Domain\Tenants\Exceptions\TenantMembershipException;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Exceptions\RoleChangeNotAllowedException;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
});

/*
|--------------------------------------------------------------------------
| MemberService Tests (FASE 29 U2)
|--------------------------------------------------------------------------
|
| F29-U2-MEM-01..16 — list, changeRole, remove, IDOR safety, owner safeguards.
| All operations go through AuthorizationService.
|
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->tenant = Tenant::factory()->create();
    $this->otherTenant = Tenant::factory()->create();

    $this->owner = User::factory()->create();
    $this->admin = User::factory()->create();
    $this->agent = User::factory()->create();
    $this->target = User::factory()->create();
    $this->outsider = User::factory()->create();

    make_tenant_member($this->owner, $this->tenant, 'owner');
    make_tenant_member($this->admin, $this->tenant, 'admin');
    make_tenant_member($this->agent, $this->tenant, 'agent');
    make_tenant_member($this->target, $this->tenant, 'agent');
    make_tenant_member($this->outsider, $this->otherTenant, 'owner');

    $this->owner->forceFill(['current_tenant_id' => $this->tenant->id])->save();
    $this->admin->forceFill(['current_tenant_id' => $this->tenant->id])->save();
    $this->agent->forceFill(['current_tenant_id' => $this->tenant->id])->save();
    $this->target->forceFill(['current_tenant_id' => $this->tenant->id])->save();

    TenantContext::setId($this->tenant->id);
    $this->service = app(MemberService::class);
});

it('F29-U2-MEM-01: owner can list members', function (): void {
    $members = $this->service->list($this->owner, $this->tenant);

    expect($members)->toHaveCount(4);
})->group('F29-U2-MEM');

it('F29-U2-MEM-02: agent cannot list members', function (): void {
    $this->service->list($this->agent, $this->tenant);
})->throws(PermissionDeniedException::class)
    ->group('F29-U2-MEM');

it('F29-U2-MEM-03: outsider cannot list members of tenant A (TenantMembershipException)', function (): void {
    $this->service->list($this->outsider, $this->tenant);
})->throws(TenantMembershipException::class)
    ->group('F29-U2-MEM');

it('F29-U2-MEM-04: owner can change agent to admin', function (): void {
    $membership = $this->service->changeRole($this->owner, $this->tenant, $this->target, UserRole::Admin);

    expect($membership->fresh()->role)->toBe(UserRole::Admin);
})->group('F29-U2-MEM');

it('F29-U2-MEM-05: owner can change admin to agent', function (): void {
    $membership = $this->service->changeRole($this->owner, $this->tenant, $this->admin, UserRole::Agent);

    expect($membership->fresh()->role)->toBe(UserRole::Agent);
})->group('F29-U2-MEM');

it('F29-U2-MEM-06: owner cannot demote last owner', function (): void {
    $this->service->changeRole($this->owner, $this->tenant, $this->owner, UserRole::Admin);
})->throws(RoleChangeNotAllowedException::class)
    ->group('F29-U2-MEM');

it('F29-U2-MEM-07: admin cannot change roles (no AssignRoles)', function (): void {
    $this->service->changeRole($this->admin, $this->tenant, $this->target, UserRole::Admin);
})->throws(PermissionDeniedException::class)
    ->group('F29-U2-MEM');

it('F29-U2-MEM-08: agent cannot change roles', function (): void {
    $this->service->changeRole($this->agent, $this->tenant, $this->target, UserRole::Admin);
})->throws(PermissionDeniedException::class)
    ->group('F29-U2-MEM');

it('F29-U2-MEM-09: same role change is no-op', function (): void {
    $membership = $this->service->changeRole($this->owner, $this->tenant, $this->target, UserRole::Agent);

    expect($membership->role)->toBe(UserRole::Agent);
})->group('F29-U2-MEM');

it('F29-U2-MEM-10: owner can remove agent', function (): void {
    $this->service->remove($this->owner, $this->tenant, $this->target);

    $this->assertDatabaseMissing('tenant_users', [
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->target->id,
        'status' => 'active',
    ]);
})->group('F29-U2-MEM');

it('F29-U2-MEM-11: cannot remove last owner', function (): void {
    $this->service->remove($this->owner, $this->tenant, $this->owner);
})->throws(RoleChangeNotAllowedException::class)
    ->group('F29-U2-MEM');

it('F29-U2-MEM-12: admin cannot remove owner', function (): void {
    $this->service->remove($this->admin, $this->tenant, $this->owner);
})->throws(RoleChangeNotAllowedException::class)
    ->group('F29-U2-MEM');

it('F29-U2-MEM-13: admin can remove agent', function (): void {
    $this->service->remove($this->admin, $this->tenant, $this->target);

    $this->assertDatabaseMissing('tenant_users', [
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->target->id,
        'status' => 'active',
    ]);
})->group('F29-U2-MEM');

it('F29-U2-MEM-14: remove nonexistent member throws', function (): void {
    $nonMember = User::factory()->create();
    $this->service->remove($this->owner, $this->tenant, $nonMember);
})->throws(TenantMembershipException::class)
    ->group('F29-U2-MEM');

it('F29-U2-MEM-15: cross-tenant mutation blocked — IDOR safety (TenantMembershipException)', function (): void {
    $this->outsider->forceFill(['current_tenant_id' => $this->otherTenant->id])->save();
    $this->service->changeRole($this->outsider, $this->otherTenant, $this->owner, UserRole::Agent);
})->throws(TenantMembershipException::class)
    ->group('F29-U2-MEM');

it('F29-U2-MEM-16: remove clears current_tenant_id if it matches', function (): void {
    $this->target->forceFill(['current_tenant_id' => $this->tenant->id])->save();

    $this->service->remove($this->owner, $this->tenant, $this->target);

    $this->target->refresh();
    expect($this->target->current_tenant_id)->not->toBe($this->tenant->id);
})->group('F29-U2-MEM');
