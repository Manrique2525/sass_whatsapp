<?php

declare(strict_types=1);

use App\Domain\Billing\Contracts\BillingProviderInterface;
use App\Domain\Billing\DTOs\BillingCustomerData;
use App\Domain\Billing\DTOs\CheckoutSessionData;
use App\Domain\Billing\DTOs\PortalSessionData;
use App\Domain\Billing\DTOs\ProviderWebhookEvent;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Models\BillingCustomer;
use App\Domain\Billing\Models\BillingWebhookEvent;
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
    $this->tenantA = Tenant::factory()->create();
    $this->tenantB = Tenant::factory()->create();
    $this->plan = Plan::factory()->create([
        'slug' => 'pro',
        'price_monthly' => 29.00,
        'stripe_price_id_monthly' => 'price_monthly_mt',
        'stripe_price_id_yearly' => 'price_yearly_mt',
    ]);

    TenantContext::setId($this->tenantA->id);

    $this->customerA = BillingCustomer::factory()->create([
        'tenant_id' => $this->tenantA->id,
        'provider' => 'stripe',
        'provider_customer_id' => 'cus_mt_A',
    ]);

    TenantContext::setId($this->tenantB->id);

    $this->customerB = BillingCustomer::factory()->create([
        'tenant_id' => $this->tenantB->id,
        'provider' => 'stripe',
        'provider_customer_id' => 'cus_mt_B',
    ]);

    Route::post('webhooks/stripe', [StripeWebhookController::class, 'handle']);
});

function makeMtEvent(string $customerId, string $subId, array $overrides = []): ProviderWebhookEvent
{
    return ProviderWebhookEvent::fromStripe(array_merge([
        'id' => 'evt_mt_'.fake()->bothify('????'),
        'type' => 'customer.subscription.created',
        'created' => (string) now()->timestamp,
        'data' => [
            'object' => [
                'id' => $subId,
                'customer' => $customerId,
                'status' => 'active',
                'cancel_at_period_end' => false,
                'quantity' => 1,
                'current_period_start' => now()->timestamp,
                'current_period_end' => now()->addMonth()->timestamp,
                'items' => [
                    'data' => [
                        ['price' => ['id' => 'price_monthly_mt']],
                    ],
                ],
            ],
        ],
    ], $overrides));
}

function registerMtFakeProvider(ProviderWebhookEvent ...$events): void
{
    $queue = new SplQueue;
    foreach ($events as $event) {
        $queue->enqueue($event);
    }

    $fakeProvider = new class($queue) implements BillingProviderInterface
    {
        use FakeBillingProviderMethods;

        private SplQueue $queue;

        public function __construct(SplQueue $queue)
        {
            $this->queue = $queue;
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
            return $this->queue->dequeue();
        }

        public function providerName(): string
        {
            return 'stripe';
        }
    };

    app()->instance(BillingProviderInterface::class, $fakeProvider);
}

it('BILL-U3-MT-01: customer A resolves to tenant A', function (): void {
    $event = makeMtEvent('cus_mt_A', 'sub_mt_A_001');
    registerMtFakeProvider($event);

    $this->postJson('webhooks/stripe', [], ['Stripe-Signature' => 't=fake,v1=fake'])->assertOk();

    $this->assertDatabaseHas('subscriptions', [
        'tenant_id' => $this->tenantA->id,
        'stripe_subscription_id' => 'sub_mt_A_001',
        'status' => SubscriptionStatus::Active,
    ]);

    $this->assertDatabaseHas('billing_webhook_events', [
        'provider_event_id' => $event->eventId,
        'tenant_id' => $this->tenantA->id,
    ]);
})->group('BILL-U3-MT-01');

it('BILL-U3-MT-02: customer B never mutates tenant A data', function (): void {
    // Create a subscription for tenant A (must force context)
    $savedContext = TenantContext::id();
    TenantContext::setId($this->tenantA->id);
    Subscription::create([
        'tenant_id' => $this->tenantA->id,
        'plan_id' => $this->plan->id,
        'stripe_subscription_id' => 'sub_mt_A_existing',
        'status' => SubscriptionStatus::Active,
        'quantity' => 1,
    ]);
    TenantContext::setId($savedContext);

    $event = makeMtEvent('cus_mt_B', 'sub_mt_B_001');
    registerMtFakeProvider($event);

    $this->postJson('webhooks/stripe', [], ['Stripe-Signature' => 't=fake,v1=fake'])->assertOk();

    // Tenant A's subscription unchanged
    $this->assertDatabaseHas('subscriptions', [
        'tenant_id' => $this->tenantA->id,
        'stripe_subscription_id' => 'sub_mt_A_existing',
        'status' => SubscriptionStatus::Active,
    ]);

    // Tenant B gets its new subscription
    $this->assertDatabaseHas('subscriptions', [
        'tenant_id' => $this->tenantB->id,
        'stripe_subscription_id' => 'sub_mt_B_001',
        'status' => SubscriptionStatus::Active,
    ]);
})->group('BILL-U3-MT-02');

it('BILL-U3-MT-03: no BillingCustomer for customer ID fails safe', function (): void {
    $event = makeMtEvent('cus_unknown_xyz', 'sub_mt_unknown');
    registerMtFakeProvider($event);

    $this->postJson('webhooks/stripe', [], ['Stripe-Signature' => 't=fake,v1=fake'])->assertOk();

    $this->assertDatabaseHas('billing_webhook_events', [
        'provider_event_id' => $event->eventId,
        'status' => 'failed',
        'error_code' => 'TENANT_NOT_FOUND',
    ]);
})->group('BILL-U3-MT-03');

it('BILL-U3-MT-04: sequential webhooks A then B are context-safe', function (): void {
    $eventA = makeMtEvent('cus_mt_A', 'sub_seq_A');
    $eventB = makeMtEvent('cus_mt_B', 'sub_seq_B');
    registerMtFakeProvider($eventA, $eventB);

    $this->postJson('webhooks/stripe', [], ['Stripe-Signature' => 't=fake,v1=fake'])->assertOk();
    $this->postJson('webhooks/stripe', [], ['Stripe-Signature' => 't=fake,v1=fake'])->assertOk();

    $this->assertDatabaseHas('subscriptions', [
        'tenant_id' => $this->tenantA->id,
        'stripe_subscription_id' => 'sub_seq_A',
    ]);
    $this->assertDatabaseHas('subscriptions', [
        'tenant_id' => $this->tenantB->id,
        'stripe_subscription_id' => 'sub_seq_B',
    ]);

    $webhookA = BillingWebhookEvent::query()->where('provider_event_id', $eventA->eventId)->first();
    $webhookB = BillingWebhookEvent::query()->where('provider_event_id', $eventB->eventId)->first();

    expect($webhookA->tenant_id)->toBe($this->tenantA->id);
    expect($webhookB->tenant_id)->toBe($this->tenantB->id);
})->group('BILL-U3-MT-04');

it('BILL-U3-MT-05: subscription B cannot sync to tenant A via customer mismatch', function (): void {
    $savedContext = TenantContext::id();
    TenantContext::setId($this->tenantA->id);
    $subA = Subscription::create([
        'tenant_id' => $this->tenantA->id,
        'plan_id' => $this->plan->id,
        'stripe_subscription_id' => 'sub_mt_protect_A',
        'status' => SubscriptionStatus::Active,
        'quantity' => 1,
    ]);
    TenantContext::setId($savedContext);

    // Webhook with customer B but subscription ID matching tenant A's
    $event = makeMtEvent('cus_mt_B', 'sub_mt_protect_A');
    registerMtFakeProvider($event);

    $this->postJson('webhooks/stripe', [], ['Stripe-Signature' => 't=fake,v1=fake'])->assertOk();

    // Tenant A's subscription should be unchanged
    $subA->refresh();
    expect($subA->status)->toBe(SubscriptionStatus::Active);
})->group('BILL-U3-MT-05');

it('BILL-U3-MT-06: global plan mapping safe across tenants', function (): void {
    $eventA = makeMtEvent('cus_mt_A', 'sub_mt_plan_A');
    $eventB = makeMtEvent('cus_mt_B', 'sub_mt_plan_B');
    registerMtFakeProvider($eventA, $eventB);
    $this->postJson('webhooks/stripe', [], ['Stripe-Signature' => 't=fake,v1=fake'])->assertOk();
    $this->postJson('webhooks/stripe', [], ['Stripe-Signature' => 't=fake,v1=fake'])->assertOk();

    $subA = Subscription::withoutTenantScope()->where('stripe_subscription_id', 'sub_mt_plan_A')->first();
    $subB = Subscription::withoutTenantScope()->where('stripe_subscription_id', 'sub_mt_plan_B')->first();

    expect($subA->plan_id)->toBe($this->plan->id);
    expect($subB->plan_id)->toBe($this->plan->id);
})->group('BILL-U3-MT-06');
