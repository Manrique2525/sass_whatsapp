<?php

declare(strict_types=1);

use App\Domain\Billing\Contracts\BillingProviderInterface;
use App\Domain\Billing\DTOs\BillingCustomerData;
use App\Domain\Billing\DTOs\CheckoutSessionData;
use App\Domain\Billing\DTOs\PortalSessionData;
use App\Domain\Billing\Models\BillingCustomer;
use App\Domain\Billing\Models\Plan;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Traits\FakeBillingProviderMethods;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
});

/*
|--------------------------------------------------------------------------
| Checkout Multi-Tenancy Tests (FASE 24 U2, ADR-093)
|--------------------------------------------------------------------------
|
| BILL-U2-MT-01..06 — Cross-tenant isolation for checkout + portal.
| Corren en SQLite :memory:.
|
*/

function mt_checkout_url(Tenant $tenant): string
{
    return '/api/v1/tenants/'.$tenant->id.'/billing/checkout';
}

function mt_portal_url(Tenant $tenant): string
{
    return '/api/v1/tenants/'.$tenant->id.'/billing/portal';
}

beforeEach(function (): void {
    $this->app->instance(BillingProviderInterface::class, new class implements BillingProviderInterface
    {
        use FakeBillingProviderMethods;

        private int $counter = 0;

        public function createCustomer(array $params): BillingCustomerData
        {
            $this->counter++;

            return BillingCustomerData::fromProvider(['id' => 'cus_tenant_'.$this->counter, 'provider' => 'stripe']);
        }

        public function retrieveCustomer(string $providerCustomerId): BillingCustomerData
        {
            return BillingCustomerData::fromProvider(['id' => $providerCustomerId, 'provider' => 'stripe']);
        }

        public function validatePrice(string $priceId): bool
        {
            return true;
        }

        public function createCheckoutSession(array $params): CheckoutSessionData
        {
            return CheckoutSessionData::fromProvider([
                'id' => 'cs_test',
                'url' => 'https://checkout.stripe.com/test',
            ]);
        }

        public function createPortalSession(array $params): PortalSessionData
        {
            return PortalSessionData::fromProvider([
                'id' => 'bps_test',
                'url' => 'https://billing.stripe.com/test',
            ]);
        }

        public function providerName(): string
        {
            return 'stripe';
        }
    });

    $this->tenantA = Tenant::factory()->create();
    $this->tenantB = Tenant::factory()->create();
    $this->userA = User::factory()->create();
    $this->userB = User::factory()->create();
    $this->planA = Plan::factory()->create([
        'price_monthly' => 29.00,
        'price_yearly' => 290.00,
        'stripe_price_id_monthly' => 'price_monthly_123',
        'stripe_price_id_yearly' => 'price_yearly_456',
    ]);

    make_tenant_member($this->userA, $this->tenantA, 'owner');
    make_tenant_member($this->userB, $this->tenantB, 'owner');

    TenantContext::setId($this->tenantA->id);
});

it('BILL-U2-MT-01: user A cannot create checkout session for tenant B', function (): void {
    $response = $this->actingAs($this->userA)->postJson(mt_checkout_url($this->tenantB), [
        'plan_id' => $this->planA->id,
        'interval' => 'monthly',
    ]);

    // Either 403, 404, or membership error depending on middleware
    expect($response->status())->toBeIn([403, 404, 409]);
})->group('BILL-U2-MT-01');

it('BILL-U2-MT-02: user B cannot create checkout session for tenant A', function (): void {
    $response = $this->actingAs($this->userB)->postJson(mt_checkout_url($this->tenantA), [
        'plan_id' => $this->planA->id,
        'interval' => 'monthly',
    ]);

    expect($response->status())->toBeIn([403, 404, 409]);
})->group('BILL-U2-MT-02');

it('BILL-U2-MT-03: user A cannot open portal for tenant B', function (): void {
    BillingCustomer::create([
        'tenant_id' => $this->tenantB->id,
        'provider' => 'stripe',
        'provider_customer_id' => 'cus_tenant_b',
    ]);

    $response = $this->actingAs($this->userA)->postJson(mt_portal_url($this->tenantB));

    expect($response->status())->toBeIn([403, 404, 409]);
})->group('BILL-U2-MT-03');

it('BILL-U2-MT-04: user B cannot open portal for tenant A', function (): void {
    BillingCustomer::create([
        'tenant_id' => $this->tenantA->id,
        'provider' => 'stripe',
        'provider_customer_id' => 'cus_tenant_a',
    ]);

    $response = $this->actingAs($this->userB)->postJson(mt_portal_url($this->tenantA));

    expect($response->status())->toBeIn([403, 404, 409]);
})->group('BILL-U2-MT-04');

it('BILL-U2-MT-05: checkout does not expose tenant A billing customer to tenant B', function (): void {
    BillingCustomer::create([
        'tenant_id' => $this->tenantA->id,
        'provider' => 'stripe',
        'provider_customer_id' => 'cus_tenant_a_secret',
    ]);

    $this->actingAs($this->userB)->postJson(mt_checkout_url($this->tenantB), [
        'plan_id' => $this->planA->id,
        'interval' => 'monthly',
    ]);

    // Tenant B should NOT see tenant A's billing customer
    $tenantBCustomers = BillingCustomer::query()
        ->withoutTenantScope()
        ->where('tenant_id', $this->tenantB->id)
        ->get();

    foreach ($tenantBCustomers as $customer) {
        expect($customer->provider_customer_id)->not->toBe('cus_tenant_a_secret');
    }
})->group('BILL-U2-MT-05');

it('BILL-U2-MT-06: each tenant gets its own billing customer', function (): void {
    $responseA = $this->actingAs($this->userA)->postJson(mt_checkout_url($this->tenantA), [
        'plan_id' => $this->planA->id,
        'interval' => 'monthly',
    ]);

    $responseB = $this->actingAs($this->userB)->postJson(mt_checkout_url($this->tenantB), [
        'plan_id' => $this->planA->id,
        'interval' => 'monthly',
    ]);

    $responseA->assertOk();
    $responseB->assertOk();

    $customerA = BillingCustomer::query()->withoutTenantScope()
        ->where('tenant_id', $this->tenantA->id)->first();
    $customerB = BillingCustomer::query()->withoutTenantScope()
        ->where('tenant_id', $this->tenantB->id)->first();

    expect($customerA)->not->toBeNull();
    expect($customerB)->not->toBeNull();
    expect($customerA->provider_customer_id)->not->toBe($customerB->provider_customer_id);
})->group('BILL-U2-MT-06');
