<?php

declare(strict_types=1);

use App\Domain\Billing\Contracts\BillingProviderInterface;
use App\Domain\Billing\DTOs\BillingCustomerData;
use App\Domain\Billing\DTOs\CheckoutSessionData;
use App\Domain\Billing\DTOs\PortalSessionData;
use App\Domain\Billing\DTOs\ProviderWebhookEvent;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Models\BillingCustomer;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Tenants\Models\Tenant;
use App\Http\Controllers\Api\Webhooks\StripeWebhookController;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Traits\FakeBillingProviderMethods;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->plan = Plan::factory()->create([
        'slug' => 'pro',
        'price_monthly' => 29.00,
        'stripe_price_id_monthly' => 'price_monthly_ord',
        'stripe_price_id_yearly' => 'price_yearly_ord',
    ]);

    TenantContext::setId($this->tenant->id);

    $this->billingCustomer = BillingCustomer::factory()->create([
        'tenant_id' => $this->tenant->id,
        'provider' => 'stripe',
        'provider_customer_id' => 'cus_ord_123',
    ]);

    Route::post('webhooks/stripe', [StripeWebhookController::class, 'handle']);
});

function makeSubEvent(array $overrides = []): ProviderWebhookEvent
{
    return ProviderWebhookEvent::fromStripe(array_merge([
        'id' => 'evt_ord_'.fake()->bothify('????'),
        'type' => 'customer.subscription.updated',
        'created' => (string) now()->timestamp,
        'data' => [
            'object' => [
                'id' => 'sub_ord_001',
                'customer' => 'cus_ord_123',
                'status' => 'active',
                'cancel_at_period_end' => false,
                'quantity' => 1,
                'current_period_start' => now()->timestamp,
                'current_period_end' => now()->addMonth()->timestamp,
                'items' => [
                    'data' => [
                        ['price' => ['id' => 'price_monthly_ord']],
                    ],
                ],
            ],
        ],
    ], $overrides));
}

function registerOrderedFakeProvider(ProviderWebhookEvent $event): void
{
    $fakeProvider = new class($event) implements BillingProviderInterface
    {
        use FakeBillingProviderMethods;

        private ProviderWebhookEvent $event;

        public function __construct(ProviderWebhookEvent $event)
        {
            $this->event = $event;
        }

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
            return CheckoutSessionData::fromProvider(['id' => 'cs_test', 'url' => 'https://checkout.stripe.com/test']);
        }

        public function createPortalSession(array $params): PortalSessionData
        {
            return PortalSessionData::fromProvider(['id' => 'bps_test', 'url' => 'https://billing.stripe.com/test']);
        }

        public function constructWebhookEvent(string $rawPayload, string $sigHeader): ProviderWebhookEvent
        {
            return $this->event;
        }

        public function providerName(): string
        {
            return 'stripe';
        }
    };

    app()->instance(BillingProviderInterface::class, $fakeProvider);
}

it('BILL-U3-ORD-01: newer event applies', function (): void {
    $sub = Subscription::create([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'stripe_subscription_id' => 'sub_ord_apply',
        'status' => SubscriptionStatus::Active,
        'quantity' => 1,
        'provider_updated_at' => now()->subHour(),
    ]);

    $event = makeSubEvent([
        'id' => 'evt_newer_001',
        'data' => [
            'object' => [
                'id' => 'sub_ord_apply',
                'customer' => 'cus_ord_123',
                'status' => 'past_due',
                'cancel_at_period_end' => false,
                'quantity' => 1,
                'current_period_start' => now()->timestamp,
                'current_period_end' => now()->addMonth()->timestamp,
                'items' => [
                    'data' => [['price' => ['id' => 'price_monthly_ord']]],
                ],
            ],
        ],
    ]);
    registerOrderedFakeProvider($event);

    $this->postJson('webhooks/stripe', [], ['Stripe-Signature' => 't=fake,v1=fake'])->assertOk();

    $sub->refresh();
    expect($sub->status)->toBe(SubscriptionStatus::PastDue);
})->group('BILL-U3-ORD-01');

it('BILL-U3-ORD-02: older event ignored (ordering)', function (): void {
    $sub = Subscription::create([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'stripe_subscription_id' => 'sub_ord_ignore',
        'status' => SubscriptionStatus::PastDue,
        'quantity' => 1,
        'provider_updated_at' => now(),
    ]);

    $event = makeSubEvent([
        'id' => 'evt_older_001',
        'created' => (string) now()->subHour()->timestamp,
        'data' => [
            'object' => [
                'id' => 'sub_ord_ignore',
                'customer' => 'cus_ord_123',
                'status' => 'active',
                'cancel_at_period_end' => false,
                'quantity' => 1,
                'current_period_start' => now()->subMonth()->timestamp,
                'current_period_end' => now()->timestamp,
                'items' => [
                    'data' => [['price' => ['id' => 'price_monthly_ord']]],
                ],
            ],
        ],
    ]);
    registerOrderedFakeProvider($event);

    $this->postJson('webhooks/stripe', [], ['Stripe-Signature' => 't=fake,v1=fake'])->assertOk();

    $sub->refresh();
    expect($sub->status)->toBe(SubscriptionStatus::PastDue); // Not resurrected to Active
})->group('BILL-U3-ORD-02');

it('BILL-U3-ORD-03: equal timestamp event allowed (idempotency)', function (): void {
    $sub = Subscription::create([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'stripe_subscription_id' => 'sub_ord_equal',
        'status' => SubscriptionStatus::Active,
        'quantity' => 1,
        'provider_updated_at' => now(),
    ]);

    $event = makeSubEvent([
        'id' => 'evt_equal_001',
        'created' => (string) $sub->provider_updated_at->timestamp,
        'data' => [
            'object' => [
                'id' => 'sub_ord_equal',
                'customer' => 'cus_ord_123',
                'status' => 'active',
                'cancel_at_period_end' => false,
                'quantity' => 1,
                'current_period_start' => now()->timestamp,
                'current_period_end' => now()->addMonth()->timestamp,
                'items' => [
                    'data' => [['price' => ['id' => 'price_monthly_ord']]],
                ],
            ],
        ],
    ]);
    registerOrderedFakeProvider($event);

    $this->postJson('webhooks/stripe', [], ['Stripe-Signature' => 't=fake,v1=fake'])->assertOk();

    $sub->refresh();
    expect($sub->status)->toBe(SubscriptionStatus::Active);
})->group('BILL-U3-ORD-03');

it('BILL-U3-ORD-04: delayed cancellation event after newer update is ignored', function (): void {
    // First: cancelled (newer)
    $sub = Subscription::create([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'stripe_subscription_id' => 'sub_ord_delayed',
        'status' => SubscriptionStatus::Cancelled,
        'quantity' => 1,
        'provider_updated_at' => now(),
    ]);

    // Stale: active event from before cancellation
    $event = makeSubEvent([
        'id' => 'evt_delayed_001',
        'created' => (string) now()->subHours(2)->timestamp,
        'data' => [
            'object' => [
                'id' => 'sub_ord_delayed',
                'customer' => 'cus_ord_123',
                'status' => 'active',
                'cancel_at_period_end' => false,
                'quantity' => 1,
                'current_period_start' => now()->subMonth()->timestamp,
                'current_period_end' => now()->timestamp,
                'items' => [
                    'data' => [['price' => ['id' => 'price_monthly_ord']]],
                ],
            ],
        ],
    ]);
    registerOrderedFakeProvider($event);

    $this->postJson('webhooks/stripe', [], ['Stripe-Signature' => 't=fake,v1=fake'])->assertOk();

    $sub->refresh();
    expect($sub->status)->toBe(SubscriptionStatus::Cancelled); // Not resurrected
})->group('BILL-U3-ORD-04');

it('BILL-U3-ORD-05: stale active event must not resurrect cancelled subscription', function (): void {
    $sub = Subscription::create([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'stripe_subscription_id' => 'sub_ord_stale',
        'status' => SubscriptionStatus::Cancelled,
        'quantity' => 1,
        'provider_updated_at' => now(),
    ]);

    $event = makeSubEvent([
        'id' => 'evt_stale_001',
        'created' => (string) now()->subDays(1)->timestamp,
        'type' => 'customer.subscription.updated',
        'data' => [
            'object' => [
                'id' => 'sub_ord_stale',
                'customer' => 'cus_ord_123',
                'status' => 'active',
                'cancel_at_period_end' => false,
                'quantity' => 1,
                'current_period_start' => now()->subMonth()->timestamp,
                'current_period_end' => now()->timestamp,
                'items' => [
                    'data' => [['price' => ['id' => 'price_monthly_ord']]],
                ],
            ],
        ],
    ]);
    registerOrderedFakeProvider($event);

    $this->postJson('webhooks/stripe', [], ['Stripe-Signature' => 't=fake,v1=fake'])->assertOk();

    $sub->refresh();
    expect($sub->status)->toBe(SubscriptionStatus::Cancelled);
})->group('BILL-U3-ORD-05');
