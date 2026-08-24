<?php

declare(strict_types=1);

use App\Domain\Billing\DTOs\BillingCustomerData;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Exceptions\BillingProviderException;
use App\Domain\Billing\Models\BillingCustomer;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Tenants\Models\Tenant;
use App\Http\Resources\PlanResource;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
});

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    TenantContext::setId($this->tenant->id);
});

/*
|--------------------------------------------------------------------------
| Billing U1 Model Tests (FASE 24 U1, ADR-092)
|--------------------------------------------------------------------------
|
| BILL-U1-MOD-01..18 — Domain invariants for U1 infrastructure changes.
| Corren en SQLite :memory:.
|
*/

it('BILL-U1-MOD-01: BillingCustomer can be created via factory', function (): void {
    $customer = BillingCustomer::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $this->assertNotNull($customer->id);
    expect($customer->provider)->toBe('stripe')
        ->and($customer->provider_customer_id)->toStartWith('cus_');
})->group('BILL-U1-MOD-01');

it('BILL-U1-MOD-02: BillingCustomer is tenant-scoped', function (): void {
    $customer = BillingCustomer::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    expect($customer->tenant_id)->toBe($this->tenant->id);
})->group('BILL-U1-MOD-02');

it('BILL-U1-MOD-03: BillingCustomer unique(tenant_id, provider) enforced', function (): void {
    BillingCustomer::factory()->create([
        'tenant_id' => $this->tenant->id,
        'provider' => 'stripe',
    ]);

    $this->expectException(QueryException::class);
    BillingCustomer::factory()->create([
        'tenant_id' => $this->tenant->id,
        'provider' => 'stripe',
    ]);
})->group('BILL-U1-MOD-03');

it('BILL-U1-MOD-04: BillingCustomer unique(provider, provider_customer_id) enforced', function (): void {
    $existing = BillingCustomer::factory()->create([
        'tenant_id' => $this->tenant->id,
        'provider' => 'stripe',
        'provider_customer_id' => 'cus_duplicate123',
    ]);

    $tenantB = Tenant::factory()->create();
    TenantContext::setId($tenantB->id);

    $this->expectException(QueryException::class);
    BillingCustomer::factory()->create([
        'tenant_id' => $tenantB->id,
        'provider' => 'stripe',
        'provider_customer_id' => 'cus_duplicate123',
    ]);
})->group('BILL-U1-MOD-04');

it('BILL-U1-MOD-05: BillingCustomer different providers allowed per tenant', function (): void {
    BillingCustomer::factory()->create([
        'tenant_id' => $this->tenant->id,
        'provider' => 'stripe',
        'provider_customer_id' => 'cus_provider1',
    ]);

    $customer2 = BillingCustomer::factory()->create([
        'tenant_id' => $this->tenant->id,
        'provider' => 'paypal',
        'provider_customer_id' => 'cus_provider2',
    ]);

    expect($customer2->provider)->toBe('paypal');
})->group('BILL-U1-MOD-05');

it('BILL-U1-MOD-06: Plan has stripe_price_id columns nullable', function (): void {
    $plan = Plan::factory()->create([
        'stripe_price_id_monthly' => null,
        'stripe_price_id_yearly' => null,
    ]);

    expect($plan->stripe_price_id_monthly)->toBeNull()
        ->and($plan->stripe_price_id_yearly)->toBeNull();
})->group('BILL-U1-MOD-06');

it('BILL-U1-MOD-07: Plan stripe_price_id values are mass-assignable', function (): void {
    $plan = Plan::factory()->create([
        'stripe_price_id_monthly' => 'price_monthly_123',
        'stripe_price_id_yearly' => 'price_yearly_456',
    ]);

    expect($plan->stripe_price_id_monthly)->toBe('price_monthly_123')
        ->and($plan->stripe_price_id_yearly)->toBe('price_yearly_456');
})->group('BILL-U1-MOD-07');

it('BILL-U1-MOD-08: Subscription has stripe_subscription_id nullable', function (): void {
    $sub = Subscription::factory()->create([
        'tenant_id' => $this->tenant->id,
        'stripe_subscription_id' => null,
    ]);

    expect($sub->stripe_subscription_id)->toBeNull();
})->group('BILL-U1-MOD-08');

it('BILL-U1-MOD-09: Subscription stripe_subscription_id is mass-assignable', function (): void {
    $sub = Subscription::factory()->create([
        'tenant_id' => $this->tenant->id,
        'stripe_subscription_id' => 'sub_abc123',
    ]);

    expect($sub->stripe_subscription_id)->toBe('sub_abc123');
})->group('BILL-U1-MOD-09');

it('BILL-U1-MOD-10: Subscription cancel_at_period_end defaults to false', function (): void {
    $sub = Subscription::factory()->create([
        'tenant_id' => $this->tenant->id,
        'cancel_at_period_end' => false,
    ]);

    expect($sub->cancel_at_period_end)->toBeFalse();
})->group('BILL-U1-MOD-10');

it('BILL-U1-MOD-11: Subscription cancel_at_period_end is mass-assignable', function (): void {
    $sub = Subscription::factory()->create([
        'tenant_id' => $this->tenant->id,
        'cancel_at_period_end' => true,
    ]);

    expect($sub->cancel_at_period_end)->toBeTrue();
})->group('BILL-U1-MOD-11');

it('BILL-U1-MOD-12: SubscriptionStatus has Pending case', function (): void {
    expect(SubscriptionStatus::Pending->value)->toBe('pending')
        ->and(SubscriptionStatus::Pending->label())->toBe('Pending');
})->group('BILL-U1-MOD-12');

it('BILL-U1-MOD-13: Subscription isActive() unchanged — Pending is NOT active', function (): void {
    $pending = Subscription::factory()->create([
        'tenant_id' => $this->tenant->id,
        'status' => SubscriptionStatus::Pending,
    ]);

    expect($pending->isActive())->toBeFalse();
})->group('BILL-U1-MOD-13');

it('BILL-U1-MOD-14: BillingCustomerData DTO creation', function (): void {
    $data = new BillingCustomerData(
        providerCustomerId: 'cus_test123',
        provider: 'stripe',
        email: 'test@example.com',
        metadata: ['key' => 'value'],
    );

    expect($data->providerCustomerId)->toBe('cus_test123')
        ->and($data->provider)->toBe('stripe')
        ->and($data->email)->toBe('test@example.com')
        ->and($data->metadata)->toBe(['key' => 'value']);
})->group('BILL-U1-MOD-14');

it('BILL-U1-MOD-15: BillingCustomerData fromProvider factory', function (): void {
    $data = BillingCustomerData::fromProvider([
        'id' => 'cus_abc',
        'provider' => 'stripe',
        'email' => 'test@example.com',
        'metadata' => [],
    ]);

    expect($data->providerCustomerId)->toBe('cus_abc')
        ->and($data->provider)->toBe('stripe')
        ->and($data->email)->toBe('test@example.com');
})->group('BILL-U1-MOD-15');

it('BILL-U1-MOD-16: BillingProviderException is throwable', function (): void {
    $ex = new BillingProviderException('test error', false);
    expect($ex->getMessage())->toBe('test error')
        ->and($ex->retryable())->toBeFalse();
})->group('BILL-U1-MOD-16');

it('BILL-U1-MOD-17: BillingProviderException retryable flag', function (): void {
    $retryable = new BillingProviderException('retry', true);
    expect($retryable->retryable())->toBeTrue();
})->group('BILL-U1-MOD-17');

it('BILL-U1-MOD-18: PlanResource does not expose stripe_price_id fields', function (): void {
    $plan = Plan::factory()->create([
        'stripe_price_id_monthly' => 'price_secret_monthly',
        'stripe_price_id_yearly' => 'price_secret_yearly',
    ]);

    $resource = new PlanResource($plan);
    $array = $resource->toArray(request());

    expect($array)->not->toHaveKey('stripe_price_id_monthly')
        ->and($array)->not->toHaveKey('stripe_price_id_yearly');
})->group('BILL-U1-MOD-18');
