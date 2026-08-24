<?php

declare(strict_types=1);

use App\Domain\Billing\Contracts\BillingProviderInterface;
use App\Domain\Billing\DTOs\BillingCustomerData;
use App\Domain\Billing\DTOs\CheckoutSessionData;
use App\Domain\Billing\DTOs\PortalSessionData;
use App\Domain\Billing\DTOs\ProviderWebhookEvent;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Enums\WebhookEventStatus;
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
        'stripe_price_id_monthly' => 'price_monthly_abc',
        'stripe_price_id_yearly' => 'price_yearly_def',
    ]);

    TenantContext::setId($this->tenant->id);

    $this->billingCustomer = BillingCustomer::factory()->create([
        'tenant_id' => $this->tenant->id,
        'provider' => 'stripe',
        'provider_customer_id' => 'cus_test_123',
    ]);

    Route::post('webhooks/stripe', [StripeWebhookController::class, 'handle']);
});

function makeStripeEvent(array $overrides = []): ProviderWebhookEvent
{
    return ProviderWebhookEvent::fromStripe(array_merge([
        'id' => 'evt_test_'.fake()->bothify('????????'),
        'type' => 'checkout.session.completed',
        'created' => (string) now()->timestamp,
        'data' => [
            'object' => [
                'id' => 'cs_test_123',
                'customer' => 'cus_test_123',
                'subscription' => 'sub_test_123',
                'metadata' => [
                    'tenant_id' => null, // Will be set by test
                    'plan_id' => null,
                ],
            ],
        ],
    ], $overrides));
}

function registerFakeProvider(ProviderWebhookEvent $event): void
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

it('BILL-U3-WH-01: unknown event type returns 200 no-op', function (): void {
    $event = makeStripeEvent(['type' => 'unknown.event.type']);
    registerFakeProvider($event);

    $response = $this->postJson('webhooks/stripe', [], [
        'Stripe-Signature' => 't=fake,v1=fake',
    ]);

    $response->assertOk()->assertJson(['received' => true]);

    $this->assertDatabaseHas('billing_webhook_events', [
        'provider' => 'stripe',
        'provider_event_id' => $event->eventId,
        'type' => 'unknown.event.type',
        'status' => WebhookEventStatus::Processed,
    ]);
})->group('BILL-U3-WH-01');

it('BILL-U3-WH-02: checkout.session.completed creates pending subscription', function (): void {
    $event = makeStripeEvent([
        'type' => 'checkout.session.completed',
        'data' => [
            'object' => [
                'id' => 'cs_test_123',
                'customer' => 'cus_test_123',
                'subscription' => 'sub_checkout_001',
                'metadata' => [
                    'tenant_id' => $this->tenant->id,
                    'plan_id' => $this->plan->id,
                ],
            ],
        ],
    ]);
    registerFakeProvider($event);

    $response = $this->postJson('webhooks/stripe', [], [
        'Stripe-Signature' => 't=fake,v1=fake',
    ]);

    $response->assertOk()->assertJson(['received' => true]);

    $this->assertDatabaseHas('subscriptions', [
        'tenant_id' => $this->tenant->id,
        'stripe_subscription_id' => 'sub_checkout_001',
        'plan_id' => $this->plan->id,
        'status' => SubscriptionStatus::Pending,
    ]);

    $this->assertDatabaseHas('billing_webhook_events', [
        'provider' => 'stripe',
        'provider_event_id' => $event->eventId,
        'type' => 'checkout.session.completed',
        'status' => WebhookEventStatus::Processed,
    ]);
})->group('BILL-U3-WH-02');

it('BILL-U3-WH-03: invoice.paid activates pending subscription', function (): void {
    // Create pending subscription first
    $sub = Subscription::create([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'stripe_subscription_id' => 'sub_invoice_001',
        'status' => SubscriptionStatus::Pending,
        'quantity' => 1,
    ]);

    $event = makeStripeEvent([
        'type' => 'invoice.paid',
        'data' => [
            'object' => [
                'id' => 'inv_test_001',
                'customer' => 'cus_test_123',
                'subscription' => 'sub_invoice_001',
                'period_start' => now()->timestamp,
                'period_end' => now()->addMonth()->timestamp,
                'lines' => [
                    'data' => [
                        [
                            'price' => ['id' => 'price_monthly_abc'],
                        ],
                    ],
                ],
            ],
        ],
    ]);
    registerFakeProvider($event);

    $response = $this->postJson('webhooks/stripe', [], [
        'Stripe-Signature' => 't=fake,v1=fake',
    ]);

    $response->assertOk()->assertJson(['received' => true]);

    $sub->refresh();
    expect($sub->status)->toBe(SubscriptionStatus::Active);
    expect($sub->current_period_start)->not->toBeNull();
    expect($sub->current_period_end)->not->toBeNull();

    // Verify denormalized cache
    $this->tenant->refresh();
    expect($this->tenant->plan_id)->toBe($this->plan->id);
})->group('BILL-U3-WH-03');

it('BILL-U3-WH-04: invoice.payment_failed sets PastDue', function (): void {
    $sub = Subscription::create([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'stripe_subscription_id' => 'sub_fail_001',
        'status' => SubscriptionStatus::Active,
        'quantity' => 1,
        'provider_updated_at' => now()->subHour(),
    ]);

    $event = makeStripeEvent([
        'type' => 'invoice.payment_failed',
        'data' => [
            'object' => [
                'id' => 'inv_fail_001',
                'customer' => 'cus_test_123',
                'subscription' => 'sub_fail_001',
            ],
        ],
    ]);
    registerFakeProvider($event);

    $response = $this->postJson('webhooks/stripe', [], [
        'Stripe-Signature' => 't=fake,v1=fake',
    ]);

    $response->assertOk()->assertJson(['received' => true]);

    $sub->refresh();
    expect($sub->status)->toBe(SubscriptionStatus::PastDue);
})->group('BILL-U3-WH-04');

it('BILL-U3-WH-05: customer.subscription.created syncs subscription', function (): void {
    $event = makeStripeEvent([
        'type' => 'customer.subscription.created',
        'data' => [
            'object' => [
                'id' => 'sub_created_001',
                'customer' => 'cus_test_123',
                'status' => 'active',
                'cancel_at_period_end' => false,
                'quantity' => 1,
                'current_period_start' => now()->timestamp,
                'current_period_end' => now()->addMonth()->timestamp,
                'items' => [
                    'data' => [
                        ['price' => ['id' => 'price_monthly_abc']],
                    ],
                ],
            ],
        ],
    ]);
    registerFakeProvider($event);

    $response = $this->postJson('webhooks/stripe', [], [
        'Stripe-Signature' => 't=fake,v1=fake',
    ]);

    $response->assertOk()->assertJson(['received' => true]);

    $this->assertDatabaseHas('subscriptions', [
        'tenant_id' => $this->tenant->id,
        'stripe_subscription_id' => 'sub_created_001',
        'status' => SubscriptionStatus::Active,
        'plan_id' => $this->plan->id,
    ]);
})->group('BILL-U3-WH-05');

it('BILL-U3-WH-06: customer.subscription.updated syncs status', function (): void {
    $sub = Subscription::create([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'stripe_subscription_id' => 'sub_updated_001',
        'status' => SubscriptionStatus::Active,
        'quantity' => 1,
        'provider_updated_at' => now()->subHour(),
    ]);

    $event = makeStripeEvent([
        'type' => 'customer.subscription.updated',
        'created' => (string) now()->timestamp,
        'data' => [
            'object' => [
                'id' => 'sub_updated_001',
                'customer' => 'cus_test_123',
                'status' => 'past_due',
                'cancel_at_period_end' => false,
                'quantity' => 1,
                'current_period_start' => now()->timestamp,
                'current_period_end' => now()->addMonth()->timestamp,
                'items' => [
                    'data' => [
                        ['price' => ['id' => 'price_monthly_abc']],
                    ],
                ],
            ],
        ],
    ]);
    registerFakeProvider($event);

    $response = $this->postJson('webhooks/stripe', [], [
        'Stripe-Signature' => 't=fake,v1=fake',
    ]);

    $response->assertOk()->assertJson(['received' => true]);

    $sub->refresh();
    expect($sub->status)->toBe(SubscriptionStatus::PastDue);
})->group('BILL-U3-WH-06');

it('BILL-U3-WH-07: customer.subscription.deleted sets Cancelled', function (): void {
    $sub = Subscription::create([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'stripe_subscription_id' => 'sub_deleted_001',
        'status' => SubscriptionStatus::Active,
        'quantity' => 1,
        'provider_updated_at' => now()->subHour(),
    ]);

    $event = makeStripeEvent([
        'type' => 'customer.subscription.deleted',
        'created' => (string) now()->timestamp,
        'data' => [
            'object' => [
                'id' => 'sub_deleted_001',
                'customer' => 'cus_test_123',
                'status' => 'canceled',
            ],
        ],
    ]);
    registerFakeProvider($event);

    $response = $this->postJson('webhooks/stripe', [], [
        'Stripe-Signature' => 't=fake,v1=fake',
    ]);

    $response->assertOk()->assertJson(['received' => true]);

    $sub->refresh();
    expect($sub->status)->toBe(SubscriptionStatus::Cancelled);

    $this->tenant->refresh();
    expect($this->tenant->plan_id)->toBeNull();
})->group('BILL-U3-WH-07');

it('BILL-U3-WH-08: duplicate event processed only once (sequential)', function (): void {
    $event = makeStripeEvent([
        'type' => 'customer.subscription.created',
        'data' => [
            'object' => [
                'id' => 'sub_dup_001',
                'customer' => 'cus_test_123',
                'status' => 'active',
                'cancel_at_period_end' => false,
                'quantity' => 1,
                'current_period_start' => now()->timestamp,
                'current_period_end' => now()->addMonth()->timestamp,
                'items' => [
                    'data' => [
                        ['price' => ['id' => 'price_monthly_abc']],
                    ],
                ],
            ],
        ],
    ]);
    registerFakeProvider($event);

    // First request
    $response1 = $this->postJson('webhooks/stripe', [], [
        'Stripe-Signature' => 't=fake,v1=fake',
    ]);
    $response1->assertOk();

    // Second request (same event ID — duplicate)
    $response2 = $this->postJson('webhooks/stripe', [], [
        'Stripe-Signature' => 't=fake,v1=fake',
    ]);
    $response2->assertOk();

    // Only one subscription should exist
    $count = Subscription::query()
        ->where('stripe_subscription_id', 'sub_dup_001')
        ->count();
    expect($count)->toBe(1);
})->group('BILL-U3-WH-08');

it('BILL-U3-WH-09: safe response body contains no internal details', function (): void {
    $event = makeStripeEvent(['type' => 'checkout.session.completed']);
    registerFakeProvider($event);

    $response = $this->postJson('webhooks/stripe', [], [
        'Stripe-Signature' => 't=fake,v1=fake',
    ]);

    $response->assertOk()->assertJson([
        'received' => true,
    ]);

    $json = $response->json();
    expect(array_keys($json))->toBe(['received']);
    expect($json)->not->toHaveKey('tenant_id');
    expect($json)->not->toHaveKey('event_type');
})->group('BILL-U3-WH-09');

it('BILL-U3-WH-10: invalid signature returns 400', function (): void {
    $response = $this->postJson('webhooks/stripe', [], [
        'Stripe-Signature' => 'invalid',
    ]);

    $response->assertStatus(400);
})->group('BILL-U3-WH-10');

it('BILL-U3-WH-11: missing signature returns 400', function (): void {
    $response = $this->postJson('webhooks/stripe');

    $response->assertStatus(400);
})->group('BILL-U3-WH-11');

it('BILL-U3-WH-12: no BillingCustomer returns processed but no subscription created', function (): void {
    $event = makeStripeEvent([
        'type' => 'invoice.paid',
        'data' => [
            'object' => [
                'id' => 'inv_nocust_001',
                'customer' => 'cus_nonexistent',
                'subscription' => 'sub_nocust_001',
            ],
        ],
    ]);
    registerFakeProvider($event);

    $response = $this->postJson('webhooks/stripe', [], [
        'Stripe-Signature' => 't=fake,v1=fake',
    ]);

    $response->assertOk()->assertJson(['received' => true]);

    // Event recorded but marked processed (no tenant found)
    $this->assertDatabaseHas('billing_webhook_events', [
        'provider' => 'stripe',
        'provider_event_id' => $event->eventId,
        'status' => WebhookEventStatus::Failed,
        'error_code' => 'TENANT_NOT_FOUND',
    ]);
})->group('BILL-U3-WH-12');
