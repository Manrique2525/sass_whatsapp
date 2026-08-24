<?php

declare(strict_types=1);

use App\Domain\Billing\Models\BillingCustomer;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Tenants\Models\Tenant;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
});

/*
|--------------------------------------------------------------------------
| Billing U1 Multi-Tenancy Tests (FASE 24 U1, ADR-092)
|--------------------------------------------------------------------------
|
| BILL-U1-MT-01..06 — Tenant isolation for BillingCustomer table.
| Corren en SQLite :memory:.
|
*/

it('BILL-U1-MT-01: Tenant A BillingCustomer is visible to A', function (): void {
    $tenantA = Tenant::factory()->create();
    TenantContext::setId($tenantA->id);

    $customer = BillingCustomer::factory()->create(['tenant_id' => $tenantA->id]);

    $found = BillingCustomer::query()->find($customer->id);
    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($customer->id);
})->group('BILL-U1-MT-01');

it('BILL-U1-MT-02: Tenant A BillingCustomer is invisible to B', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    TenantContext::setId($tenantA->id);
    $customer = BillingCustomer::factory()->create(['tenant_id' => $tenantA->id]);

    TenantContext::setId($tenantB->id);
    $found = BillingCustomer::query()->find($customer->id);
    expect($found)->toBeNull();
})->group('BILL-U1-MT-02');

it('BILL-U1-MT-03: Cross-tenant duplicate provider_customer_id blocked', function (): void {
    $tenantA = Tenant::factory()->create();
    TenantContext::setId($tenantA->id);
    BillingCustomer::factory()->create([
        'tenant_id' => $tenantA->id,
        'provider_customer_id' => 'cus_shared_123',
    ]);

    $tenantB = Tenant::factory()->create();
    TenantContext::setId($tenantB->id);

    $this->expectException(QueryException::class);
    BillingCustomer::factory()->create([
        'tenant_id' => $tenantB->id,
        'provider_customer_id' => 'cus_shared_123',
    ]);
})->group('BILL-U1-MT-03');

it('BILL-U1-MT-04: Subscription stripe fields respect tenant isolation', function (): void {
    $tenantA = Tenant::factory()->create();
    TenantContext::setId($tenantA->id);
    $subA = Subscription::factory()->create([
        'tenant_id' => $tenantA->id,
        'stripe_subscription_id' => 'sub_tenantA',
    ]);

    $tenantB = Tenant::factory()->create();
    TenantContext::setId($tenantB->id);

    $found = Subscription::query()->where('stripe_subscription_id', 'sub_tenantA')->first();
    expect($found)->toBeNull();
})->group('BILL-U1-MT-04');

it('BILL-U1-MT-05: BillingCustomer FK cascade on tenant delete', function (): void {
    $tenantA = Tenant::factory()->create();
    TenantContext::setId($tenantA->id);
    BillingCustomer::factory()->create(['tenant_id' => $tenantA->id]);

    $tenantA->delete();

    $count = BillingCustomer::query()->where('tenant_id', $tenantA->id)->count();
    expect($count)->toBe(0);
})->group('BILL-U1-MT-05');

it('BILL-U1-MT-06: BillingCustomer index(tenant_id) exists', function (): void {
    $tenantA = Tenant::factory()->create();
    TenantContext::setId($tenantA->id);
    BillingCustomer::factory()->create(['tenant_id' => $tenantA->id]);

    $indexes = Schema::getIndexes('billing_customers');
    $indexNames = array_column($indexes, 'name');
    expect($indexNames)->toContain('billing_customers_tenant_provider_unique')
        ->and($indexNames)->toContain('billing_customers_provider_customer_unique');
})->group('BILL-U1-MT-06');
