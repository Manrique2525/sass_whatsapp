<?php

declare(strict_types=1);

use App\Application\Billing\Services\CheckoutService;
use App\Domain\Billing\Contracts\BillingProviderInterface;
use App\Domain\Billing\DTOs\BillingCustomerData;
use App\Domain\Billing\DTOs\CheckoutSessionData;
use App\Domain\Billing\DTOs\PortalSessionData;
use App\Domain\Billing\Exceptions\BillingProviderException;
use App\Domain\Billing\Exceptions\PlanNotFoundException;
use App\Domain\Billing\Models\BillingCustomer;
use App\Domain\Billing\Models\Plan;
use App\Domain\Tenants\Exceptions\PermissionDeniedException;
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
| CheckoutService Tests (FASE 24 U2, ADR-093)
|--------------------------------------------------------------------------
|
| BILL-U2-SVC-01..14 — CheckoutSession, PortalSession, BillingCustomer logic.
| Uses a FakeProvider to avoid Stripe calls.
| Corren en SQLite :memory:.
|
*/

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->owner = User::factory()->create();
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
    TenantContext::setId($this->tenant->id);
});

it('BILL-U2-SVC-01: createCheckoutSession creates billing customer if missing', function (): void {
    $fakeProvider = new class implements BillingProviderInterface
    {
        public int $customerCount = 0;

        public function createCustomer(array $params): BillingCustomerData
        {
            $this->customerCount++;

            return BillingCustomerData::fromProvider([
                'id' => 'cus_fake_'.$this->customerCount,
                'provider' => 'stripe',
            ]);
        }

        public function retrieveCustomer(string $providerCustomerId): BillingCustomerData
        {
            return BillingCustomerData::fromProvider([
                'id' => $providerCustomerId,
                'provider' => 'stripe',
            ]);
        }

        public function validatePrice(string $priceId): bool
        {
            return true;
        }

        public function createCheckoutSession(array $params): CheckoutSessionData
        {
            return CheckoutSessionData::fromProvider([
                'id' => 'cs_test_123',
                'url' => 'https://checkout.stripe.com/test',
            ]);
        }

        public function createPortalSession(array $params): PortalSessionData
        {
            return PortalSessionData::fromProvider([
                'id' => 'bps_test_123',
                'url' => 'https://billing.stripe.com/test',
            ]);
        }

        public function providerName(): string
        {
            return 'stripe';
        }
    };

    $this->app->instance(BillingProviderInterface::class, $fakeProvider);

    $service = app(CheckoutService::class);

    $result = $service->createCheckoutSession(
        $this->owner,
        $this->tenant,
        $this->plan->id,
        'monthly',
    );

    expect($result->url)->toBe('https://checkout.stripe.com/test');
    expect($fakeProvider->customerCount)->toBe(1);

    $this->assertDatabaseHas('billing_customers', [
        'tenant_id' => $this->tenant->id,
        'provider' => 'stripe',
    ]);
})->group('BILL-U2-SVC-01');
it('BILL-U2-SVC-02: createCheckoutSession reuses existing billing customer', function (): void {
    BillingCustomer::create([
        'tenant_id' => $this->tenant->id,
        'provider' => 'stripe',
        'provider_customer_id' => 'cus_existing_123',
    ]);

    $fakeProvider = new class implements BillingProviderInterface
    {
        public int $customerCount = 0;

        public function createCustomer(array $params): BillingCustomerData
        {
            $this->customerCount++;

            return BillingCustomerData::fromProvider([
                'id' => 'cus_new',
                'provider' => 'stripe',
            ]);
        }

        public function retrieveCustomer(string $providerCustomerId): BillingCustomerData
        {
            return BillingCustomerData::fromProvider([
                'id' => $providerCustomerId,
                'provider' => 'stripe',
            ]);
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
    };

    $this->app->instance(BillingProviderInterface::class, $fakeProvider);
    $service = app(CheckoutService::class);

    $service->createCheckoutSession(
        $this->owner,
        $this->tenant,
        $this->plan->id,
        'monthly',
    );

    expect($fakeProvider->customerCount)->toBe(0);
})->group('BILL-U2-SVC-02');

it('BILL-U2-SVC-03: createCheckoutSession throws for non-existent plan', function (): void {
    $fakeProvider = new class implements BillingProviderInterface
    {
        public function createCustomer(array $params): BillingCustomerData
        {
            return BillingCustomerData::fromProvider(['id' => 'cus', 'provider' => 'stripe']);
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
            return CheckoutSessionData::fromProvider(['id' => 'cs', 'url' => 'https://checkout.stripe.com']);
        }

        public function createPortalSession(array $params): PortalSessionData
        {
            return PortalSessionData::fromProvider(['id' => 'bps', 'url' => 'https://billing.stripe.com']);
        }

        public function providerName(): string
        {
            return 'stripe';
        }
    };

    $this->app->instance(BillingProviderInterface::class, $fakeProvider);

    $service = app(CheckoutService::class);
    $service->createCheckoutSession(
        $this->owner,
        $this->tenant,
        '00000000-0000-0000-0000-000000000000',
        'monthly',
    );
})->group('BILL-U2-SVC-03')
    ->throws(PlanNotFoundException::class);

it('BILL-U2-SVC-04: createCheckoutSession throws for free plan', function (): void {
    $fakeProvider = new class implements BillingProviderInterface
    {
        public function createCustomer(array $params): BillingCustomerData
        {
            return BillingCustomerData::fromProvider(['id' => 'cus', 'provider' => 'stripe']);
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
            return CheckoutSessionData::fromProvider(['id' => 'cs', 'url' => 'https://checkout.stripe.com']);
        }

        public function createPortalSession(array $params): PortalSessionData
        {
            return PortalSessionData::fromProvider(['id' => 'bps', 'url' => 'https://billing.stripe.com']);
        }

        public function providerName(): string
        {
            return 'stripe';
        }
    };

    $this->app->instance(BillingProviderInterface::class, $fakeProvider);

    $service = app(CheckoutService::class);
    $service->createCheckoutSession(
        $this->owner,
        $this->tenant,
        $this->freePlan->id,
        'monthly',
    );
})->group('BILL-U2-SVC-04')
    ->throws(BillingProviderException::class, 'Este plan es gratuito. Use la asignación directa.');

it('BILL-U2-SVC-05: createCheckoutSession throws for invalid interval', function (): void {
    $fakeProvider = new class implements BillingProviderInterface
    {
        public function createCustomer(array $params): BillingCustomerData
        {
            return BillingCustomerData::fromProvider(['id' => 'cus', 'provider' => 'stripe']);
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
            return CheckoutSessionData::fromProvider(['id' => 'cs', 'url' => 'https://checkout.stripe.com']);
        }

        public function createPortalSession(array $params): PortalSessionData
        {
            return PortalSessionData::fromProvider(['id' => 'bps', 'url' => 'https://billing.stripe.com']);
        }

        public function providerName(): string
        {
            return 'stripe';
        }
    };

    $this->app->instance(BillingProviderInterface::class, $fakeProvider);

    $service = app(CheckoutService::class);
    $service->createCheckoutSession(
        $this->owner,
        $this->tenant,
        $this->plan->id,
        'weekly',
    );
})->group('BILL-U2-SVC-05')
    ->throws(BillingProviderException::class, 'Intervalo inválido: weekly');

it('BILL-U2-SVC-06: createCheckoutSession throws when no price configured', function (): void {
    $noPricePlan = Plan::factory()->create([
        'price_monthly' => 10.00,
        'price_yearly' => 0,
        'stripe_price_id_monthly' => null,
        'stripe_price_id_yearly' => null,
    ]);

    $fakeProvider = new class implements BillingProviderInterface
    {
        public function createCustomer(array $params): BillingCustomerData
        {
            return BillingCustomerData::fromProvider(['id' => 'cus', 'provider' => 'stripe']);
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
            return CheckoutSessionData::fromProvider(['id' => 'cs', 'url' => 'https://checkout.stripe.com']);
        }

        public function createPortalSession(array $params): PortalSessionData
        {
            return PortalSessionData::fromProvider(['id' => 'bps', 'url' => 'https://billing.stripe.com']);
        }

        public function providerName(): string
        {
            return 'stripe';
        }
    };

    $this->app->instance(BillingProviderInterface::class, $fakeProvider);

    $service = app(CheckoutService::class);
    $service->createCheckoutSession(
        $this->owner,
        $this->tenant,
        $noPricePlan->id,
        'yearly',
    );
})->group('BILL-U2-SVC-06')
    ->throws(BillingProviderException::class, 'no tiene precio configurado para el intervalo yearly');

it('BILL-U2-SVC-07: createCheckoutSession throws for unauthorized user (agent)', function (): void {
    $agent = User::factory()->create();
    make_tenant_member($agent, $this->tenant, 'agent');

    $fakeProvider = new class implements BillingProviderInterface
    {
        public function createCustomer(array $params): BillingCustomerData
        {
            return BillingCustomerData::fromProvider(['id' => 'cus', 'provider' => 'stripe']);
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
            return CheckoutSessionData::fromProvider(['id' => 'cs', 'url' => 'https://checkout.stripe.com']);
        }

        public function createPortalSession(array $params): PortalSessionData
        {
            return PortalSessionData::fromProvider(['id' => 'bps', 'url' => 'https://billing.stripe.com']);
        }

        public function providerName(): string
        {
            return 'stripe';
        }
    };

    $this->app->instance(BillingProviderInterface::class, $fakeProvider);

    $service = app(CheckoutService::class);
    $service->createCheckoutSession(
        $agent,
        $this->tenant,
        $this->plan->id,
        'monthly',
    );
})->group('BILL-U2-SVC-07')
    ->throws(PermissionDeniedException::class);

it('BILL-U2-SVC-08: createPortalSession returns portal URL', function (): void {
    BillingCustomer::create([
        'tenant_id' => $this->tenant->id,
        'provider' => 'stripe',
        'provider_customer_id' => 'cus_existing_123',
    ]);

    $fakeProvider = new class implements BillingProviderInterface
    {
        public function createCustomer(array $params): BillingCustomerData
        {
            return BillingCustomerData::fromProvider(['id' => 'cus', 'provider' => 'stripe']);
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
            return CheckoutSessionData::fromProvider(['id' => 'cs', 'url' => 'https://checkout.stripe.com']);
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
    };

    $this->app->instance(BillingProviderInterface::class, $fakeProvider);

    $service = app(CheckoutService::class);
    $result = $service->createPortalSession($this->owner, $this->tenant);

    expect($result->url)->toBe('https://billing.stripe.com/test');
})->group('BILL-U2-SVC-08');

it('BILL-U2-SVC-09: createPortalSession throws when no billing customer exists', function (): void {
    $fakeProvider = new class implements BillingProviderInterface
    {
        public function createCustomer(array $params): BillingCustomerData
        {
            return BillingCustomerData::fromProvider(['id' => 'cus', 'provider' => 'stripe']);
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
            return CheckoutSessionData::fromProvider(['id' => 'cs', 'url' => 'https://checkout.stripe.com']);
        }

        public function createPortalSession(array $params): PortalSessionData
        {
            return PortalSessionData::fromProvider(['id' => 'bps', 'url' => 'https://billing.stripe.com']);
        }

        public function providerName(): string
        {
            return 'stripe';
        }
    };

    $this->app->instance(BillingProviderInterface::class, $fakeProvider);

    $service = app(CheckoutService::class);
    $service->createPortalSession($this->owner, $this->tenant);
})->group('BILL-U2-SVC-09')
    ->throws(BillingProviderException::class, 'No hay cliente de facturación');

it('BILL-U2-SVC-10: createPortalSession throws for admin (not owner)', function (): void {
    $admin = User::factory()->create();
    make_tenant_member($admin, $this->tenant, 'admin');

    BillingCustomer::create([
        'tenant_id' => $this->tenant->id,
        'provider' => 'stripe',
        'provider_customer_id' => 'cus_existing_123',
    ]);

    $fakeProvider = new class implements BillingProviderInterface
    {
        public function createCustomer(array $params): BillingCustomerData
        {
            return BillingCustomerData::fromProvider(['id' => 'cus', 'provider' => 'stripe']);
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
            return CheckoutSessionData::fromProvider(['id' => 'cs', 'url' => 'https://checkout.stripe.com']);
        }

        public function createPortalSession(array $params): PortalSessionData
        {
            return PortalSessionData::fromProvider(['id' => 'bps', 'url' => 'https://billing.stripe.com']);
        }

        public function providerName(): string
        {
            return 'stripe';
        }
    };

    $this->app->instance(BillingProviderInterface::class, $fakeProvider);

    $service = app(CheckoutService::class);
    $service->createPortalSession($admin, $this->tenant);
})->group('BILL-U2-SVC-10')
    ->throws(PermissionDeniedException::class);
