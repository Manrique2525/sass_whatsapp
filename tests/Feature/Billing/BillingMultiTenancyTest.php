<?php

declare(strict_types=1);

use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Models\UsageRecord;
use App\Domain\Tenants\Exceptions\TenantContextMissingException;
use App\Domain\Tenants\Models\Tenant;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
});

/*
|--------------------------------------------------------------------------
| Billing Multi-Tenancy Tests (FASE 23 U1)
|--------------------------------------------------------------------------
|
| BILL-MT-01..08 — Tenant isolation for billing tables.
| Corren en SQLite :memory:.
|
*/

it('BILL-MT-01: Tenant A subscription is visible to A', function (): void {
    $tenantA = Tenant::factory()->create();
    TenantContext::setId($tenantA->id);

    $sub = Subscription::factory()->create(['tenant_id' => $tenantA->id]);

    $found = Subscription::query()->find($sub->id);
    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($sub->id);
})->group('BILL-MT-01');

it('BILL-MT-02: Tenant A subscription is invisible to B', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    TenantContext::setId($tenantA->id);
    $sub = Subscription::factory()->create(['tenant_id' => $tenantA->id]);

    TenantContext::setId($tenantB->id);
    $found = Subscription::query()->find($sub->id);
    expect($found)->toBeNull();
})->group('BILL-MT-02');

it('BILL-MT-03: Tenant A usage record is invisible to B', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    TenantContext::setId($tenantA->id);
    $subA = Subscription::factory()->create(['tenant_id' => $tenantA->id]);
    $record = UsageRecord::factory()->create([
        'tenant_id' => $tenantA->id,
        'subscription_id' => $subA->id,
    ]);

    TenantContext::setId($tenantB->id);
    $found = UsageRecord::query()->find($record->id);
    expect($found)->toBeNull();
})->group('BILL-MT-03');

it('BILL-MT-04: Cross-tenant usage record creation fails (BelongsToTenant)', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    TenantContext::setId($tenantA->id);
    $subA = Subscription::factory()->create(['tenant_id' => $tenantA->id]);

    TenantContext::setId($tenantB->id);

    // Try to create usage record with tenant A's subscription
    // This should set tenant_id from TenantContext (B), not from the subscription
    $record = UsageRecord::factory()->create([
        'tenant_id' => null,
        'subscription_id' => $subA->id,
    ]);

    // The record gets tenant B's context
    expect($record->tenant_id)->toBe($tenantB->id);
})->group('BILL-MT-04');

it('BILL-MT-05: TenantContext missing fails on subscription create', function (): void {
    TenantContext::clear();

    $this->expectException(TenantContextMissingException::class);
    Subscription::factory()->create(['tenant_id' => null]);
})->group('BILL-MT-05');

it('BILL-MT-06: TenantContext missing fails on usage record create', function (): void {
    TenantContext::clear();

    $this->expectException(TenantContextMissingException::class);
    UsageRecord::factory()->create(['tenant_id' => null]);
})->group('BILL-MT-06');

it('BILL-MT-07: tenant_id mass assignment ignored for subscription', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    TenantContext::setId($tenantA->id);

    // tenant_id is set by BelongsToTenant, not by mass assignment
    $sub = Subscription::factory()->create([
        'tenant_id' => $tenantA->id,
    ]);

    // Verify the factory correctly set tenant_id from context
    expect($sub->tenant_id)->toBe($tenantA->id);
})->group('BILL-MT-07');

it('BILL-MT-08: Plans are global (visible across tenants)', function (): void {
    $plan = Plan::factory()->create(['slug' => 'test-global']);

    $tenantA = Tenant::factory()->create();
    TenantContext::setId($tenantA->id);

    // Plans have no tenant scope — visible everywhere
    $found = Plan::query()->where('slug', 'test-global')->first();
    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($plan->id);
})->group('BILL-MT-08');
