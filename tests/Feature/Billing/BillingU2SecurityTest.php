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

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
});

/*
|--------------------------------------------------------------------------
| Checkout Security Tests (FASE 24 U2, ADR-093)
|--------------------------------------------------------------------------
|
| BILL-U2-SEC-01..06 — Authorization matrix, safe redirect, input rejection.
| Corren en SQLite :memory:.
|
*/

function sec_checkout_url(Tenant $tenant): string
{
    return '/api/v1/tenants/'.$tenant->id.'/billing/checkout';
}

function sec_portal_url(Tenant $tenant): string
{
    return '/api/v1/tenants/'.$tenant->id.'/billing/portal';
}

beforeEach(function (): void {
    $this->app->instance(BillingProviderInterface::class, new class implements BillingProviderInterface
    {
        public function createCustomer(array $params): BillingCustomerData
        {
            return BillingCustomerData::fromProvider(['id' => 'cus_new', 'provider' => 'stripe']);
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

    $this->tenant = Tenant::factory()->create();
    $this->owner = User::factory()->create();
    $this->admin = User::factory()->create();
    $this->agent = User::factory()->create();
    $this->outsider = User::factory()->create();
    $this->plan = Plan::factory()->create([
        'price_monthly' => 29.00,
        'price_yearly' => 290.00,
        'stripe_price_id_monthly' => 'price_monthly_123',
        'stripe_price_id_yearly' => 'price_yearly_456',
    ]);

    make_tenant_member($this->owner, $this->tenant, 'owner');
    make_tenant_member($this->admin, $this->tenant, 'admin');
    make_tenant_member($this->agent, $this->tenant, 'agent');

    TenantContext::setId($this->tenant->id);
});

it('BILL-U2-SEC-01: billing.manage requires owner role', function (): void {
    $ownerResponse = $this->actingAs($this->owner)->postJson(sec_checkout_url($this->tenant), [
        'plan_id' => $this->plan->id,
        'interval' => 'monthly',
    ]);
    expect($ownerResponse->status())->toBe(200);

    $adminResponse = $this->actingAs($this->admin)->postJson(sec_checkout_url($this->tenant), [
        'plan_id' => $this->plan->id,
        'interval' => 'monthly',
    ]);
    expect($adminResponse->status())->toBe(403);

    $agentResponse = $this->actingAs($this->agent)->postJson(sec_checkout_url($this->tenant), [
        'plan_id' => $this->plan->id,
        'interval' => 'monthly',
    ]);
    expect($agentResponse->status())->toBe(403);
})->group('BILL-U2-SEC-01');

it('BILL-U2-SEC-02: portal requires owner role', function (): void {
    BillingCustomer::create([
        'tenant_id' => $this->tenant->id,
        'provider' => 'stripe',
        'provider_customer_id' => 'cus_existing',
    ]);

    $ownerResponse = $this->actingAs($this->owner)->postJson(sec_portal_url($this->tenant));
    expect($ownerResponse->status())->toBe(200);

    $adminResponse = $this->actingAs($this->admin)->postJson(sec_portal_url($this->tenant));
    expect($adminResponse->status())->toBe(403);

    $agentResponse = $this->actingAs($this->agent)->postJson(sec_portal_url($this->tenant));
    expect($agentResponse->status())->toBe(403);
})->group('BILL-U2-SEC-02');

it('BILL-U2-SEC-03: unauthenticated user gets 401', function (): void {
    $response = $this->postJson(sec_checkout_url($this->tenant), [
        'plan_id' => $this->plan->id,
        'interval' => 'monthly',
    ]);

    $response->assertStatus(401);
})->group('BILL-U2-SEC-03');

it('BILL-U2-SEC-04: outsider user gets 403/404', function (): void {
    $response = $this->actingAs($this->outsider)->postJson(sec_checkout_url($this->tenant), [
        'plan_id' => $this->plan->id,
        'interval' => 'monthly',
    ]);

    expect($response->status())->toBeIn([403, 404]);
})->group('BILL-U2-SEC-04');

it('BILL-U2-SEC-05: checkout does not return price_id or amount in response', function (): void {
    $response = $this->actingAs($this->owner)->postJson(sec_checkout_url($this->tenant), [
        'plan_id' => $this->plan->id,
        'interval' => 'monthly',
    ]);

    $response->assertOk();
    $data = $response->json();

    expect(array_keys($data))->toBe(['checkout_url']);
    expect($data)->not->toHaveKey('price_id');
    expect($data)->not->toHaveKey('amount');
    expect($data)->not->toHaveKey('currency');
})->group('BILL-U2-SEC-05');

it('BILL-U2-SEC-06: checkout creates billing customer idempotently', function (): void {
    $this->actingAs($this->owner)->postJson(sec_checkout_url($this->tenant), [
        'plan_id' => $this->plan->id,
        'interval' => 'monthly',
    ]);

    $count1 = BillingCustomer::query()->withoutTenantScope()
        ->where('tenant_id', $this->tenant->id)->count();

    $this->actingAs($this->owner)->postJson(sec_checkout_url($this->tenant), [
        'plan_id' => $this->plan->id,
        'interval' => 'monthly',
    ]);

    $count2 = BillingCustomer::query()->withoutTenantScope()
        ->where('tenant_id', $this->tenant->id)->count();

    expect($count1)->toBe(1);
    expect($count2)->toBe(1);
})->group('BILL-U2-SEC-06');
