<?php

declare(strict_types=1);

use App\Application\Billing\Services\SubscriptionService;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Exceptions\PlanNotFoundException;
use App\Domain\Billing\Exceptions\SubscriptionNotFoundException;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Tenants\Exceptions\PermissionDeniedException;
use App\Domain\Tenants\Exceptions\TenantMembershipException;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
});

/*
|--------------------------------------------------------------------------
| SubscriptionService Tests (FASE 29 U1)
|--------------------------------------------------------------------------
|
| F29-U1-SUB-01..14 — Direct service-level tests.
| Covers: listPlans, showPlan, currentSubscription, assignPlan, changePlan, cancel.
| Covers: tenant isolation, authorization, transaction behavior.
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

    $this->plan = Plan::factory()->create(['is_active' => true]);
    $this->otherPlan = Plan::factory()->create(['is_active' => true]);

    TenantContext::setId($this->tenant->id);

    $this->service = app(SubscriptionService::class);
});

it('F29-U1-SUB-01: listPlans returns active plans for authorized user', function (): void {
    $plans = $this->service->listPlans($this->owner, $this->tenant);

    expect($plans)->toHaveCount(2);
    expect($plans->pluck('id'))->toContain($this->plan->id, $this->otherPlan->id);
})->group('F29-U1-SUB');

it('F29-U1-SUB-02: listPlans denied for agent', function (): void {
    $this->service->listPlans($this->agent, $this->tenant);
})->throws(PermissionDeniedException::class)
    ->group('F29-U1-SUB');

it('F29-U1-SUB-03: listPlans denied for outsider', function (): void {
    $this->service->listPlans($this->outsider, $this->tenant);
})->throws(TenantMembershipException::class)
    ->group('F29-U1-SUB');

it('F29-U1-SUB-04: currentSubscription returns null when none exists', function (): void {
    $sub = $this->service->currentSubscription($this->owner, $this->tenant);

    expect($sub)->toBeNull();
})->group('F29-U1-SUB');

it('F29-U1-SUB-05: currentSubscription returns active subscription', function (): void {
    Subscription::factory()->create([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => SubscriptionStatus::Active,
    ]);

    $sub = $this->service->currentSubscription($this->owner, $this->tenant);

    expect($sub)->not->toBeNull();
    expect($sub->plan_id)->toBe($this->plan->id);
    expect($sub->status)->toBe(SubscriptionStatus::Active);
})->group('F29-U1-SUB');

it('F29-U1-SUB-06: assignPlan creates subscription and syncs tenant plan_id', function (): void {
    $sub = $this->service->assignPlan($this->owner, $this->tenant, $this->plan->id);

    expect($sub->plan_id)->toBe($this->plan->id);
    expect($sub->status)->toBe(SubscriptionStatus::Active);

    $this->assertDatabaseHas('subscriptions', [
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => SubscriptionStatus::Active,
    ]);

    $this->tenant->refresh();
    expect($this->tenant->plan_id)->toBe($this->plan->id);
})->group('F29-U1-SUB');

it('F29-U1-SUB-07: assignPlan cancels existing and creates new', function (): void {
    $existing = Subscription::factory()->create([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => SubscriptionStatus::Active,
    ]);

    $sub = $this->service->assignPlan($this->owner, $this->tenant, $this->otherPlan->id);

    expect($sub->plan_id)->toBe($this->otherPlan->id);

    $existing->refresh();
    expect($existing->status)->toBe(SubscriptionStatus::Cancelled);
    expect($existing->deleted_at)->not->toBeNull();
})->group('F29-U1-SUB');

it('F29-U1-SUB-08: changePlan updates existing subscription', function (): void {
    Subscription::factory()->create([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => SubscriptionStatus::Active,
    ]);

    $sub = $this->service->changePlan($this->owner, $this->tenant, $this->otherPlan->id);

    expect($sub->plan_id)->toBe($this->otherPlan->id);

    $this->tenant->refresh();
    expect($this->tenant->plan_id)->toBe($this->otherPlan->id);
})->group('F29-U1-SUB');

it('F29-U1-SUB-09: changePlan same plan is no-op', function (): void {
    $existing = Subscription::factory()->create([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => SubscriptionStatus::Active,
    ]);

    $sub = $this->service->changePlan($this->owner, $this->tenant, $this->plan->id);

    expect($sub->id)->toBe($existing->id);
    expect($sub->plan_id)->toBe($this->plan->id);
})->group('F29-U1-SUB');

it('F29-U1-SUB-10: changePlan without active subscription throws', function (): void {
    $this->service->changePlan($this->owner, $this->tenant, $this->plan->id);
})->throws(SubscriptionNotFoundException::class)
    ->group('F29-U1-SUB');

it('F29-U1-SUB-11: changePlan with nonexistent plan throws', function (): void {
    Subscription::factory()->create([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => SubscriptionStatus::Active,
    ]);

    $this->service->changePlan($this->owner, $this->tenant, '00000000-0000-0000-0000-000000000000');
})->throws(PlanNotFoundException::class)
    ->group('F29-U1-SUB');

it('F29-U1-SUB-12: cancel removes subscription and clears tenant plan_id', function (): void {
    Subscription::factory()->create([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => SubscriptionStatus::Active,
    ]);
    $this->tenant->update(['plan_id' => $this->plan->id]);

    $this->service->cancel($this->owner, $this->tenant);

    $this->assertDatabaseHas('subscriptions', [
        'tenant_id' => $this->tenant->id,
        'status' => SubscriptionStatus::Cancelled,
    ]);

    $this->tenant->refresh();
    expect($this->tenant->plan_id)->toBeNull();
})->group('F29-U1-SUB');

it('F29-U1-SUB-13: cancel without subscription throws', function (): void {
    $this->service->cancel($this->owner, $this->tenant);
})->throws(SubscriptionNotFoundException::class)
    ->group('F29-U1-SUB');

it('F29-U1-SUB-14: outsider cannot manage subscriptions in tenant A', function (): void {
    $this->service->assignPlan($this->outsider, $this->tenant, $this->plan->id);
})->throws(TenantMembershipException::class)
    ->group('F29-U1-SUB');
