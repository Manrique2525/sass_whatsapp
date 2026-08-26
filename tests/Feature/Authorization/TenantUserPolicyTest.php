<?php

declare(strict_types=1);

use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\TenantUser;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use App\Policies\TenantUserPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
});

/*
|--------------------------------------------------------------------------
| TenantUserPolicy Tests (FASE 29 U1)
|--------------------------------------------------------------------------
|
| F29-U1-POL-01..10 — Direct policy authorization tests.
| Covers: viewAny, update, delete for Owner/Admin/Agent + cross-tenant.
| Corren en SQLite :memory:.
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

    TenantContext::setId($this->tenant->id);

    $this->membership = TenantUser::where('tenant_id', $this->tenant->id)
        ->where('user_id', $this->agent->id)
        ->first();
});

it('F29-U1-POL-01: owner can viewAny users', function (): void {
    $policy = app(TenantUserPolicy::class);

    expect($policy->viewAny($this->owner, $this->tenant))->toBeTrue();
})->group('F29-U1-POL');

it('F29-U1-POL-02: admin can viewAny users', function (): void {
    $policy = app(TenantUserPolicy::class);

    expect($policy->viewAny($this->admin, $this->tenant))->toBeTrue();
})->group('F29-U1-POL');

it('F29-U1-POL-03: agent cannot viewAny users', function (): void {
    $policy = app(TenantUserPolicy::class);

    expect($policy->viewAny($this->agent, $this->tenant))->toBeFalse();
})->group('F29-U1-POL');

it('F29-U1-POL-04: owner can update membership', function (): void {
    $policy = app(TenantUserPolicy::class);

    expect($policy->update($this->owner, $this->membership, $this->tenant))->toBeTrue();
})->group('F29-U1-POL');

it('F29-U1-POL-05: admin can update membership', function (): void {
    $policy = app(TenantUserPolicy::class);

    expect($policy->update($this->admin, $this->membership, $this->tenant))->toBeTrue();
})->group('F29-U1-POL');

it('F29-U1-POL-06: agent cannot update membership', function (): void {
    $policy = app(TenantUserPolicy::class);

    expect($policy->update($this->agent, $this->membership, $this->tenant))->toBeFalse();
})->group('F29-U1-POL');

it('F29-U1-POL-07: owner can delete membership', function (): void {
    $policy = app(TenantUserPolicy::class);

    expect($policy->delete($this->owner, $this->membership, $this->tenant))->toBeTrue();
})->group('F29-U1-POL');

it('F29-U1-POL-08: admin can delete membership', function (): void {
    $policy = app(TenantUserPolicy::class);

    expect($policy->delete($this->admin, $this->membership, $this->tenant))->toBeTrue();
})->group('F29-U1-POL');

it('F29-U1-POL-09: agent cannot delete membership', function (): void {
    $policy = app(TenantUserPolicy::class);

    expect($policy->delete($this->agent, $this->membership, $this->tenant))->toBeFalse();
})->group('F29-U1-POL');

it('F29-U1-POL-10: outsider cannot manage users in tenant A', function (): void {
    $policy = app(TenantUserPolicy::class);

    expect($policy->viewAny($this->outsider, $this->tenant))->toBeFalse();
    expect($policy->update($this->outsider, $this->membership, $this->tenant))->toBeFalse();
    expect($policy->delete($this->outsider, $this->membership, $this->tenant))->toBeFalse();
})->group('F29-U1-POL');
