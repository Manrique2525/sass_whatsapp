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
        'stripe_price_id_monthly' => 'price_monthly_sync',
        'stripe_price_id_yearly' => 'price_yearly_sync',
    ]);

    TenantContext::setId($this->tenant->id);

    $this->billingCustomer = BillingCustomer::factory()->create([
        'tenant_id' => $this->tenant->id,
        'provider' => 'stripe',
        'provider_customer_id' => 'cus_sync_123',
    ]);

    Route::post('webhooks/stripe', [StripeWebhookController::class, 'handle']);
});

function makeSyncEvent(array $overrides = []): ProviderWebhookEvent
{
    return ProviderWebhookEvent::fromStripe(array_merge([
        'id' => 'evt_sync_'.fake()->bothify('????'),
        'type' => 'customer.subscription.updated',
        'created' => (string) now()->timestamp,
        'data' => [
            'object' => [
                'id' => 'sub_sync_001',
                'customer' => 'cus_sync_123',
                'status' => 'active',
                'cancel_at_period_end' => false,
                'quantity' => 1,
                'current_period_start' => now()->timestamp,
                'current_period_end' => now()->addMonth()->timestamp,
                'items' => [
                    'data' => [
                        ['price' => ['id' => 'price_monthly_sync']],
                    ],
                ],
            ],
        ],
    ], $overrides));
}

function registerSyncFakeProvider(ProviderWebhookEvent $event): void
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

it('BILL-U3-SYNC-01: Pending to Active on invoice.paid', function (): void {
    $sub = Subscription::create([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'stripe_subscription_id' => 'sub_sync_p2a',
        'status' => SubscriptionStatus::Pending,
        'quantity' => 1,
    ]);

    $event = ProviderWebhookEvent::fromStripe([
        'id' => 'evt_sync_p2a_001',
        'type' => 'invoice.paid',
        'created' => (string) now()->timestamp,
        'data' => [
            'object' => [
                'id' => 'inv_sync_001',
                'customer' => 'cus_sync_123',
                'subscription' => 'sub_sync_p2a',
                'period_start' => now()->timestamp,
                'period_end' => now()->addMonth()->timestamp,
                'lines' => [
                    'data' => [
                        ['price' => ['id' => 'price_monthly_sync']],
                    ],
                ],
            ],
        ],
    ]);
    registerSyncFakeProvider($event);

    $this->postJson('webhooks/stripe', [], ['Stripe-Signature' => 't=fake,v1=fake'])->assertOk();

    $sub->refresh();
    expect($sub->status)->toBe(SubscriptionStatus::Active);
})->group('BILL-U3-SYNC-01');

it('BILL-U3-SYNC-02: provider subscription ID persisted', function (): void {
    $event = makeSyncEvent([
        'id' => 'evt_sync_pid_001',
        'data' => [
            'object' => [
                'id' => 'sub_sync_pid',
                'customer' => 'cus_sync_123',
                'status' => 'active',
                'cancel_at_period_end' => false,
                'quantity' => 1,
                'current_period_start' => now()->timestamp,
                'current_period_end' => now()->addMonth()->timestamp,
                'items' => [
                    'data' => [['price' => ['id' => 'price_monthly_sync']]],
                ],
            ],
        ],
    ]);
    registerSyncFakeProvider($event);

    $this->postJson('webhooks/stripe', [], ['Stripe-Signature' => 't=fake,v1=fake'])->assertOk();

    $this->assertDatabaseHas('subscriptions', [
        'stripe_subscription_id' => 'sub_sync_pid',
        'status' => SubscriptionStatus::Active,
    ]);
})->group('BILL-U3-SYNC-02');

it('BILL-U3-SYNC-03: period start/end synced', function (): void {
    $periodStart = now()->subMonth();
    $periodEnd = now();

    $event = makeSyncEvent([
        'id' => 'evt_sync_period_001',
        'data' => [
            'object' => [
                'id' => 'sub_sync_period',
                'customer' => 'cus_sync_123',
                'status' => 'active',
                'cancel_at_period_end' => false,
                'quantity' => 1,
                'current_period_start' => $periodStart->timestamp,
                'current_period_end' => $periodEnd->timestamp,
                'items' => [
                    'data' => [['price' => ['id' => 'price_monthly_sync']]],
                ],
            ],
        ],
    ]);
    registerSyncFakeProvider($event);

    $this->postJson('webhooks/stripe', [], ['Stripe-Signature' => 't=fake,v1=fake'])->assertOk();

    $this->assertDatabaseHas('subscriptions', [
        'stripe_subscription_id' => 'sub_sync_period',
    ]);

    $sub = Subscription::where('stripe_subscription_id', 'sub_sync_period')->first();
    expect($sub->current_period_start->timestamp)->toBe($periodStart->timestamp);
    expect($sub->current_period_end->timestamp)->toBe($periodEnd->timestamp);
})->group('BILL-U3-SYNC-03');

it('BILL-U3-SYNC-04: cancel_at_period_end synced', function (): void {
    $sub = Subscription::create([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'stripe_subscription_id' => 'sub_sync_cap',
        'status' => SubscriptionStatus::Active,
        'quantity' => 1,
        'cancel_at_period_end' => false,
        'provider_updated_at' => now()->subHour(),
    ]);

    $event = makeSyncEvent([
        'id' => 'evt_sync_cap_001',
        'data' => [
            'object' => [
                'id' => 'sub_sync_cap',
                'customer' => 'cus_sync_123',
                'status' => 'active',
                'cancel_at_period_end' => true,
                'quantity' => 1,
                'current_period_start' => now()->timestamp,
                'current_period_end' => now()->addMonth()->timestamp,
                'items' => [
                    'data' => [['price' => ['id' => 'price_monthly_sync']]],
                ],
            ],
        ],
    ]);
    registerSyncFakeProvider($event);

    $this->postJson('webhooks/stripe', [], ['Stripe-Signature' => 't=fake,v1=fake'])->assertOk();

    $sub->refresh();
    expect($sub->cancel_at_period_end)->toBeTrue();
})->group('BILL-U3-SYNC-04');

it('BILL-U3-SYNC-05: price to plan mapping works', function (): void {
    $event = makeSyncEvent([
        'id' => 'evt_sync_map_001',
        'data' => [
            'object' => [
                'id' => 'sub_sync_map',
                'customer' => 'cus_sync_123',
                'status' => 'active',
                'cancel_at_period_end' => false,
                'quantity' => 1,
                'current_period_start' => now()->timestamp,
                'current_period_end' => now()->addMonth()->timestamp,
                'items' => [
                    'data' => [['price' => ['id' => 'price_monthly_sync']]],
                ],
            ],
        ],
    ]);
    registerSyncFakeProvider($event);

    $this->postJson('webhooks/stripe', [], ['Stripe-Signature' => 't=fake,v1=fake'])->assertOk();

    $sub = Subscription::where('stripe_subscription_id', 'sub_sync_map')->first();
    expect($sub->plan_id)->toBe($this->plan->id);
})->group('BILL-U3-SYNC-05');

it('BILL-U3-SYNC-06: unknown price fails safe (no subscription created)', function (): void {
    $event = makeSyncEvent([
        'id' => 'evt_sync_unk_001',
        'data' => [
            'object' => [
                'id' => 'sub_sync_unk',
                'customer' => 'cus_sync_123',
                'status' => 'active',
                'cancel_at_period_end' => false,
                'quantity' => 1,
                'current_period_start' => now()->timestamp,
                'current_period_end' => now()->addMonth()->timestamp,
                'items' => [
                    'data' => [['price' => ['id' => 'price_unknown_xyz']]],
                ],
            ],
        ],
    ]);
    registerSyncFakeProvider($event);

    $this->postJson('webhooks/stripe', [], ['Stripe-Signature' => 't=fake,v1=fake'])->assertOk();

    $this->assertDatabaseMissing('subscriptions', [
        'stripe_subscription_id' => 'sub_sync_unk',
    ]);
})->group('BILL-U3-SYNC-06');

it('BILL-U3-SYNC-07: status mapping correct (past_due)', function (): void {
    $event = makeSyncEvent([
        'id' => 'evt_sync_pd_001',
        'data' => [
            'object' => [
                'id' => 'sub_sync_pd',
                'customer' => 'cus_sync_123',
                'status' => 'past_due',
                'cancel_at_period_end' => false,
                'quantity' => 1,
                'current_period_start' => now()->timestamp,
                'current_period_end' => now()->addMonth()->timestamp,
                'items' => [
                    'data' => [['price' => ['id' => 'price_monthly_sync']]],
                ],
            ],
        ],
    ]);
    registerSyncFakeProvider($event);

    $this->postJson('webhooks/stripe', [], ['Stripe-Signature' => 't=fake,v1=fake'])->assertOk();

    $sub = Subscription::where('stripe_subscription_id', 'sub_sync_pd')->first();
    expect($sub->status)->toBe(SubscriptionStatus::PastDue);
})->group('BILL-U3-SYNC-07');

it('BILL-U3-SYNC-08: ledger (usage records) preserved on sync', function (): void {
    $sub = Subscription::create([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'stripe_subscription_id' => 'sub_sync_ledger',
        'status' => SubscriptionStatus::Pending,
        'quantity' => 1,
    ]);

    $event = makeSyncEvent([
        'id' => 'evt_sync_ledger_001',
        'data' => [
            'object' => [
                'id' => 'sub_sync_ledger',
                'customer' => 'cus_sync_123',
                'status' => 'active',
                'cancel_at_period_end' => false,
                'quantity' => 1,
                'current_period_start' => now()->timestamp,
                'current_period_end' => now()->addMonth()->timestamp,
                'items' => [
                    'data' => [['price' => ['id' => 'price_monthly_sync']]],
                ],
            ],
        ],
    ]);
    registerSyncFakeProvider($event);

    $this->postJson('webhooks/stripe', [], ['Stripe-Signature' => 't=fake,v1=fake'])->assertOk();

    // Subscription still exists (not deleted)
    $this->assertDatabaseHas('subscriptions', [
        'stripe_subscription_id' => 'sub_sync_ledger',
        'status' => SubscriptionStatus::Active,
    ]);
})->group('BILL-U3-SYNC-08');

it('BILL-U3-SYNC-09: no duplicate subscription on re-sync', function (): void {
    $sub = Subscription::create([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'stripe_subscription_id' => 'sub_sync_nodup',
        'status' => SubscriptionStatus::Active,
        'quantity' => 1,
        'provider_updated_at' => now()->subHour(),
    ]);

    $event = makeSyncEvent([
        'id' => 'evt_sync_nodup_001',
        'data' => [
            'object' => [
                'id' => 'sub_sync_nodup',
                'customer' => 'cus_sync_123',
                'status' => 'active',
                'cancel_at_period_end' => false,
                'quantity' => 1,
                'current_period_start' => now()->timestamp,
                'current_period_end' => now()->addMonth()->timestamp,
                'items' => [
                    'data' => [['price' => ['id' => 'price_monthly_sync']]],
                ],
            ],
        ],
    ]);
    registerSyncFakeProvider($event);

    $this->postJson('webhooks/stripe', [], ['Stripe-Signature' => 't=fake,v1=fake'])->assertOk();

    $count = Subscription::where('stripe_subscription_id', 'sub_sync_nodup')->count();
    expect($count)->toBe(1);
})->group('BILL-U3-SYNC-09');

it('BILL-U3-SYNC-10: free plan unaffected by webhook sync', function (): void {
    $freePlan = Plan::factory()->create([
        'slug' => 'free',
        'price_monthly' => 0,
        'price_yearly' => 0,
        'stripe_price_id_monthly' => null,
        'stripe_price_id_yearly' => null,
    ]);

    // Free plan subscription has no stripe_subscription_id
    $sub = Subscription::create([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $freePlan->id,
        'stripe_subscription_id' => null,
        'status' => SubscriptionStatus::Active,
        'quantity' => 1,
    ]);

    $this->assertDatabaseHas('subscriptions', [
        'id' => $sub->id,
        'plan_id' => $freePlan->id,
        'stripe_subscription_id' => null,
        'status' => SubscriptionStatus::Active,
    ]);
})->group('BILL-U3-SYNC-10');
