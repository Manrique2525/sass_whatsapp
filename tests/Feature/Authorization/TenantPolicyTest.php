<?php

declare(strict_types=1);

use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use App\Policies\TenantPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
});

/*
|--------------------------------------------------------------------------
| TenantPolicy Tests (FASE 29 U1)
|--------------------------------------------------------------------------
|
| F29-U1-POL-11..18 — Direct policy authorization tests.
| Covers: viewAny, view, update, switch for member + non-member + suspended.
| Corren en SQLite :memory:.
|
*/

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->otherTenant = Tenant::factory()->create();

    $this->owner = User::factory()->create();
    $this->outsider = User::factory()->create();

    make_tenant_member($this->owner, $this->tenant, 'owner');
    make_tenant_member($this->outsider, $this->otherTenant, 'owner');

    TenantContext::setId($this->tenant->id);
});

it('F29-U1-POL-11: viewAny always returns true', function (): void {
    $policy = app(TenantPolicy::class);

    expect($policy->viewAny($this->owner))->toBeTrue();
    expect($policy->viewAny($this->outsider))->toBeTrue();
})->group('F29-U1-POL');

it('F29-U1-POL-12: member can view own tenant', function (): void {
    $policy = app(TenantPolicy::class);

    expect($policy->view($this->owner, $this->tenant))->toBeTrue();
})->group('F29-U1-POL');

it('F29-U1-POL-13: non-member cannot view tenant', function (): void {
    $policy = app(TenantPolicy::class);

    expect($policy->view($this->outsider, $this->tenant))->toBeFalse();
})->group('F29-U1-POL');

it('F29-U1-POL-14: member can update own tenant', function (): void {
    $policy = app(TenantPolicy::class);

    expect($policy->update($this->owner, $this->tenant))->toBeTrue();
})->group('F29-U1-POL');

it('F29-U1-POL-15: non-member cannot update tenant', function (): void {
    $policy = app(TenantPolicy::class);

    expect($policy->update($this->outsider, $this->tenant))->toBeFalse();
})->group('F29-U1-POL');

it('F29-U1-POL-16: member can switch to active tenant', function (): void {
    $policy = app(TenantPolicy::class);

    expect($policy->switch($this->owner, $this->tenant))->toBeTrue();
})->group('F29-U1-POL');

it('F29-U1-POL-17: non-member cannot switch to tenant', function (): void {
    $policy = app(TenantPolicy::class);

    expect($policy->switch($this->outsider, $this->tenant))->toBeFalse();
})->group('F29-U1-POL');

it('F29-U1-POL-18: suspended tenant cannot be switched to', function (): void {
    $suspended = Tenant::factory()->create(['status' => 'suspended']);
    make_tenant_member($this->owner, $suspended, 'owner');

    $policy = app(TenantPolicy::class);

    expect($policy->switch($this->owner, $suspended))->toBeFalse();
})->group('F29-U1-POL');
