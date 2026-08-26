<?php

declare(strict_types=1);

use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\TenantInvitation;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use App\Policies\TenantInvitationPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
});

/*
|--------------------------------------------------------------------------
| TenantInvitationPolicy Tests (FASE 29 U1)
|--------------------------------------------------------------------------
|
| F29-U1-POL-19..26 — Direct policy authorization tests.
| Covers: create, viewAny, update, delete for Owner/Admin/Agent + cross-tenant.
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

    $this->invitationId = (string) Str::uuid();
    DB::table('tenant_invitations')->insert([
        'id' => $this->invitationId,
        'tenant_id' => $this->tenant->id,
        'email' => 'invitee@example.com',
        'role' => 'agent',
        'token_hash' => hash('sha256', 'fake-token'),
        'invited_by' => $this->owner->id,
        'status' => 'pending',
        'expires_at' => now()->addDays(7),
    ]);
    $this->invitation = TenantInvitation::withoutGlobalScopes()->find($this->invitationId);
});

it('F29-U1-POL-19: owner can create invitation', function (): void {
    $policy = app(TenantInvitationPolicy::class);

    expect($policy->create($this->owner, $this->tenant))->toBeTrue();
})->group('F29-U1-POL');

it('F29-U1-POL-20: admin can create invitation', function (): void {
    $policy = app(TenantInvitationPolicy::class);

    expect($policy->create($this->admin, $this->tenant))->toBeTrue();
})->group('F29-U1-POL');

it('F29-U1-POL-21: agent cannot create invitation', function (): void {
    $policy = app(TenantInvitationPolicy::class);

    expect($policy->create($this->agent, $this->tenant))->toBeFalse();
})->group('F29-U1-POL');

it('F29-U1-POL-22: owner can viewAny invitations', function (): void {
    $policy = app(TenantInvitationPolicy::class);

    expect($policy->viewAny($this->owner, $this->tenant))->toBeTrue();
})->group('F29-U1-POL');

it('F29-U1-POL-23: agent cannot viewAny invitations', function (): void {
    $policy = app(TenantInvitationPolicy::class);

    expect($policy->viewAny($this->agent, $this->tenant))->toBeFalse();
})->group('F29-U1-POL');

it('F29-U1-POL-24: owner can delete invitation', function (): void {
    $policy = app(TenantInvitationPolicy::class);

    expect($policy->delete($this->owner, $this->invitation, $this->tenant))->toBeTrue();
})->group('F29-U1-POL');

it('F29-U1-POL-25: admin can delete invitation', function (): void {
    $policy = app(TenantInvitationPolicy::class);

    expect($policy->delete($this->admin, $this->invitation, $this->tenant))->toBeTrue();
})->group('F29-U1-POL');

it('F29-U1-POL-26: outsider cannot manage invitations in tenant A', function (): void {
    $policy = app(TenantInvitationPolicy::class);

    expect($policy->create($this->outsider, $this->tenant))->toBeFalse();
    expect($policy->viewAny($this->outsider, $this->tenant))->toBeFalse();
    expect($policy->delete($this->outsider, $this->invitation, $this->tenant))->toBeFalse();
})->group('F29-U1-POL');
