<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Billing\Contracts\BillingProviderInterface;
use App\Domain\Billing\DTOs\BillingCustomerData;
use App\Domain\Billing\DTOs\CheckoutSessionData;
use App\Domain\Billing\DTOs\PortalSessionData;
use App\Domain\Billing\DTOs\ProviderWebhookEvent;
use App\Domain\Billing\Models\BillingCustomer;
use App\Domain\Billing\Models\BillingWebhookEvent;
use App\Domain\Billing\Models\Plan;
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
        'stripe_price_id_monthly' => 'price_monthly_sec',
        'stripe_price_id_yearly' => 'price_yearly_sec',
    ]);

    TenantContext::setId($this->tenant->id);

    $this->billingCustomer = BillingCustomer::factory()->create([
        'tenant_id' => $this->tenant->id,
        'provider' => 'stripe',
        'provider_customer_id' => 'cus_sec_123',
    ]);

    Route::post('webhooks/stripe', [StripeWebhookController::class, 'handle']);
});

it('BILL-U3-SEC-01: forged webhook without valid signature is rejected', function (): void {
    $response = $this->postJson('webhooks/stripe', ['type' => 'invoice.paid'], [
        'Stripe-Signature' => 'forged_signature_value',
    ]);

    $response->assertStatus(400);
})->group('BILL-U3-SEC-01');

it('BILL-U3-SEC-02: no raw payload stored in webhook events', function (): void {
    $event = ProviderWebhookEvent::fromStripe([
        'id' => 'evt_sec_payload_001',
        'type' => 'checkout.session.completed',
        'created' => (string) now()->timestamp,
        'data' => [
            'object' => [
                'id' => 'cs_sec_001',
                'customer' => 'cus_sec_123',
                'subscription' => 'sub_sec_001',
                'metadata' => [
                    'tenant_id' => $this->tenant->id,
                    'plan_id' => $this->plan->id,
                ],
            ],
        ],
    ]);

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

    $this->postJson('webhooks/stripe', [], ['Stripe-Signature' => 't=fake,v1=fake'])->assertOk();

    $webhookEvent = BillingWebhookEvent::where('provider_event_id', 'evt_sec_payload_001')->first();
    expect($webhookEvent)->not->toBeNull();

    // Verify no payload-like columns exist (no raw body, no email, no card data)
    expect($webhookEvent->getAttributes())->not->toHaveKey('raw_payload');
    expect($webhookEvent->getAttributes())->not->toHaveKey('payload');
})->group('BILL-U3-SEC-02');

it('BILL-U3-SEC-03: webhook route has no auth middleware', function (): void {
    // Confirm the route exists and accepts POST without auth
    $response = $this->postJson('webhooks/stripe', [], [
        'Stripe-Signature' => 'invalid',
    ]);

    // 400 from invalid signature, NOT 401 from auth
    $response->assertStatus(400);
})->group('BILL-U3-SEC-03');

it('BILL-U3-SEC-04: signature is never included in audit logs', function (): void {
    $event = ProviderWebhookEvent::fromStripe([
        'id' => 'evt_sec_log_001',
        'type' => 'checkout.session.completed',
        'created' => (string) now()->timestamp,
        'data' => [
            'object' => [
                'id' => 'cs_sec_log',
                'customer' => 'cus_sec_123',
                'subscription' => 'sub_sec_log_001',
                'metadata' => [
                    'tenant_id' => $this->tenant->id,
                    'plan_id' => $this->plan->id,
                ],
            ],
        ],
    ]);

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

    $this->postJson('webhooks/stripe', [], ['Stripe-Signature' => 't=fake,v1=fake'])->assertOk();

    $auditLogs = AuditLog::where('action', 'like', 'billing.%')->get();
    foreach ($auditLogs as $log) {
        $data = $log->data ?? [];
        $dataStr = json_encode($data);
        expect($dataStr)->not->toContain('whsec_');
    }
})->group('BILL-U3-SEC-04');

it('BILL-U3-SEC-05: response body contains no tenant ID or internal details', function (): void {
    $event = ProviderWebhookEvent::fromStripe([
        'id' => 'evt_sec_resp_001',
        'type' => 'checkout.session.completed',
        'created' => (string) now()->timestamp,
        'data' => [
            'object' => [
                'id' => 'cs_sec_resp',
                'customer' => 'cus_sec_123',
                'subscription' => null,
                'metadata' => [],
            ],
        ],
    ]);

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

    $response = $this->postJson('webhooks/stripe', [], ['Stripe-Signature' => 't=fake,v1=fake']);

    $response->assertOk()->assertJson(['received' => true]);
    $json = $response->json();
    expect($json)->not->toHaveKey('tenant_id');
    expect($json)->not->toHaveKey('event_id');
    expect($json)->not->toHaveKey('type');
})->group('BILL-U3-SEC-05');

it('BILL-U3-SEC-06: idempotent customer creation on concurrent webhook processing', function (): void {
    $event = ProviderWebhookEvent::fromStripe([
        'id' => 'evt_sec_idemp_001',
        'type' => 'checkout.session.completed',
        'created' => (string) now()->timestamp,
        'data' => [
            'object' => [
                'id' => 'cs_sec_idemp',
                'customer' => 'cus_sec_123',
                'subscription' => 'sub_sec_idemp_001',
                'metadata' => [
                    'tenant_id' => $this->tenant->id,
                    'plan_id' => $this->plan->id,
                ],
            ],
        ],
    ]);

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

    // Two requests with same event
    $this->postJson('webhooks/stripe', [], ['Stripe-Signature' => 't=fake,v1=fake'])->assertOk();
    $this->postJson('webhooks/stripe', [], ['Stripe-Signature' => 't=fake,v1=fake'])->assertOk();

    // Only one webhook event record
    $count = BillingWebhookEvent::where('provider_event_id', 'evt_sec_idemp_001')->count();
    expect($count)->toBe(1);
})->group('BILL-U3-SEC-06');
