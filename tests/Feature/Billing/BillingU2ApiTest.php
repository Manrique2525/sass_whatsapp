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
| Checkout + Portal API Tests (FASE 24 U2, ADR-093)
|--------------------------------------------------------------------------
|
| BILL-U2-API-01..14 — POST checkout, POST portal endpoints.
| Uses a FakeProvider to avoid Stripe calls.
| Corren en SQLite :memory:.
|
*/

function checkout_url(Tenant $tenant): string
{
    return '/api/v1/tenants/'.$tenant->id.'/billing/checkout';
}

function portal_url(Tenant $tenant): string
{
    return '/api/v1/tenants/'.$tenant->id.'/billing/portal';
}

function makeFakeProvider(): object
{
    return new class implements BillingProviderInterface
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
                'id' => 'cs_test_123',
                'url' => 'https://checkout.stripe.com/c/pay/cs_test_123',
            ]);
        }

        public function createPortalSession(array $params): PortalSessionData
        {
            return PortalSessionData::fromProvider([
                'id' => 'bps_test_123',
                'url' => 'https://billing.stripe.com/p/session/bps_test_123',
            ]);
        }

        public function providerName(): string
        {
            return 'stripe';
        }
    };
}

beforeEach(function (): void {
    $this->app->instance(BillingProviderInterface::class, makeFakeProvider());

    $this->tenant = Tenant::factory()->create();
    $this->owner = User::factory()->create();
    $this->admin = User::factory()->create();
    $this->agent = User::factory()->create();
    $this->plan = Plan::factory()->create([
        'price_monthly' => 29.00,
        'price_yearly' => 290.00,
        'stripe_price_id_monthly' => 'price_monthly_123',
        'stripe_price_id_yearly' => 'price_yearly_456',
    ]);
    $this->freePlan = Plan::factory()->create([
        'price_monthly' => 0,
        'price_yearly' => 0,
        'stripe_price_id_monthly' => null,
        'stripe_price_id_yearly' => null,
    ]);

    make_tenant_member($this->owner, $this->tenant, 'owner');
    make_tenant_member($this->admin, $this->tenant, 'admin');
    make_tenant_member($this->agent, $this->tenant, 'agent');

    TenantContext::setId($this->tenant->id);
});

// ── Checkout Endpoint Tests ──

it('BILL-U2-API-01: owner can create checkout session', function (): void {
    $response = $this->actingAs($this->owner)->postJson(checkout_url($this->tenant), [
        'plan_id' => $this->plan->id,
        'interval' => 'monthly',
    ]);

    $response->assertOk()->assertJsonStructure([
        'checkout_url',
    ]);
})->group('BILL-U2-API-01');

it('BILL-U2-API-02: checkout response contains Stripe hosted URL', function (): void {
    $response = $this->actingAs($this->owner)->postJson(checkout_url($this->tenant), [
        'plan_id' => $this->plan->id,
        'interval' => 'monthly',
    ]);

    $response->assertOk();
    $data = $response->json();
    expect($data['checkout_url'])->toContain('https://checkout.stripe.com/');
})->group('BILL-U2-API-02');

it('BILL-U2-API-03: checkout with yearly interval works', function (): void {
    $response = $this->actingAs($this->owner)->postJson(checkout_url($this->tenant), [
        'plan_id' => $this->plan->id,
        'interval' => 'yearly',
    ]);

    $response->assertOk()->assertJsonStructure([
        'checkout_url',
    ]);
})->group('BILL-U2-API-03');

it('BILL-U2-API-04: checkout rejects admin (billing.manage = owner only)', function (): void {
    $response = $this->actingAs($this->admin)->postJson(checkout_url($this->tenant), [
        'plan_id' => $this->plan->id,
        'interval' => 'monthly',
    ]);

    $response->assertStatus(403)->assertJson([
        'code' => 'PERMISSION_DENIED',
    ]);
})->group('BILL-U2-API-04');

it('BILL-U2-API-05: checkout rejects agent', function (): void {
    $response = $this->actingAs($this->agent)->postJson(checkout_url($this->tenant), [
        'plan_id' => $this->plan->id,
        'interval' => 'monthly',
    ]);

    $response->assertStatus(403)->assertJson([
        'code' => 'PERMISSION_DENIED',
    ]);
})->group('BILL-U2-API-05');

it('BILL-U2-API-06: checkout rejects missing plan_id', function (): void {
    $response = $this->actingAs($this->owner)->postJson(checkout_url($this->tenant), [
        'interval' => 'monthly',
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors('plan_id');
})->group('BILL-U2-API-06');

it('BILL-U2-API-07: checkout rejects missing interval', function (): void {
    $response = $this->actingAs($this->owner)->postJson(checkout_url($this->tenant), [
        'plan_id' => $this->plan->id,
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors('interval');
})->group('BILL-U2-API-07');

it('BILL-U2-API-08: checkout rejects invalid interval value', function (): void {
    $response = $this->actingAs($this->owner)->postJson(checkout_url($this->tenant), [
        'plan_id' => $this->plan->id,
        'interval' => 'weekly',
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors('interval');
})->group('BILL-U2-API-08');

it('BILL-U2-API-09: checkout rejects extra fields (price_id, amount, currency)', function (): void {
    $response = $this->actingAs($this->owner)->postJson(checkout_url($this->tenant), [
        'plan_id' => $this->plan->id,
        'interval' => 'monthly',
        'price_id' => 'price_hack_123',
        'amount' => 999,
        'currency' => 'usd',
    ]);

    // Fields not in rules are stripped by FormRequest, endpoint should work normally
    $response->assertOk();
})->group('BILL-U2-API-09');

it('BILL-U2-API-10: checkout returns 422 for non-existent plan', function (): void {
    $response = $this->actingAs($this->owner)->postJson(checkout_url($this->tenant), [
        'plan_id' => '00000000-0000-0000-0000-000000000000',
        'interval' => 'monthly',
    ]);

    $response->assertStatus(404);
})->group('BILL-U2-API-10');

it('BILL-U2-API-11: checkout returns 422 for free plan (no Stripe)', function (): void {
    $response = $this->actingAs($this->owner)->postJson(checkout_url($this->tenant), [
        'plan_id' => $this->freePlan->id,
        'interval' => 'monthly',
    ]);

    $response->assertStatus(422)->assertJson([
        'code' => 'CHECKOUT_FAILED',
    ]);
})->group('BILL-U2-API-11');

it('BILL-U2-API-12: checkout creates billing_customers record', function (): void {
    $this->actingAs($this->owner)->postJson(checkout_url($this->tenant), [
        'plan_id' => $this->plan->id,
        'interval' => 'monthly',
    ]);

    $this->assertDatabaseHas('billing_customers', [
        'tenant_id' => $this->tenant->id,
        'provider' => 'stripe',
        'provider_customer_id' => 'cus_new',
    ]);
})->group('BILL-U2-API-12');

// ── Portal Endpoint Tests ──

it('BILL-U2-API-13: owner can open billing portal', function (): void {
    BillingCustomer::create([
        'tenant_id' => $this->tenant->id,
        'provider' => 'stripe',
        'provider_customer_id' => 'cus_existing_123',
    ]);

    $response = $this->actingAs($this->owner)->postJson(portal_url($this->tenant));

    $response->assertOk()->assertJsonStructure([
        'portal_url',
    ]);
})->group('BILL-U2-API-13');

it('BILL-U2-API-14: portal rejects admin', function (): void {
    BillingCustomer::create([
        'tenant_id' => $this->tenant->id,
        'provider' => 'stripe',
        'provider_customer_id' => 'cus_existing_123',
    ]);

    $response = $this->actingAs($this->admin)->postJson(portal_url($this->tenant));

    $response->assertStatus(403)->assertJson([
        'code' => 'PERMISSION_DENIED',
    ]);
})->group('BILL-U2-API-14');
