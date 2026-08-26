<?php

declare(strict_types=1);

use App\Application\Billing\Services\BillingCustomerService;
use App\Domain\Billing\Contracts\BillingProviderInterface;
use App\Domain\Billing\DTOs\BillingCustomerData;
use App\Domain\Billing\DTOs\CheckoutSessionData;
use App\Domain\Billing\DTOs\PortalSessionData;
use App\Domain\Billing\Exceptions\BillingProviderException;
use App\Domain\Billing\Models\BillingCustomer;
use App\Domain\Tenants\Models\Tenant;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Traits\FakeBillingProviderMethods;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
});

/*
|--------------------------------------------------------------------------
| BillingCustomerService Tests (FASE 29 U1)
|--------------------------------------------------------------------------
|
| F29-U1-CUST-01..08 — Direct service-level tests.
| Covers: findByTenant, ensureCustomer, createCustomer, race condition.
| Uses fake BillingProviderInterface — no real Stripe calls.
| Corren en SQLite :memory:.
|
*/

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->otherTenant = Tenant::factory()->create();

    TenantContext::setId($this->tenant->id);

    $this->fakeProvider = new class implements BillingProviderInterface
    {
        use FakeBillingProviderMethods;

        public int $callCount = 0;

        public function createCustomer(array $params): BillingCustomerData
        {
            $this->callCount++;

            return new BillingCustomerData(
                providerCustomerId: 'cus_fake_'.$this->callCount,
                provider: 'stripe',
                email: $params['email'] ?? null,
                metadata: $params['metadata'] ?? [],
            );
        }

        public function retrieveCustomer(string $providerCustomerId): BillingCustomerData
        {
            return new BillingCustomerData(
                providerCustomerId: $providerCustomerId,
                provider: 'stripe',
                email: null,
                metadata: [],
            );
        }

        public function validatePrice(string $priceId): bool
        {
            return true;
        }

        public function createCheckoutSession(array $params): CheckoutSessionData
        {
            throw new RuntimeException('Not implemented in fake.');
        }

        public function createPortalSession(array $params): PortalSessionData
        {
            throw new RuntimeException('Not implemented in fake.');
        }

        public function providerName(): string
        {
            return 'stripe';
        }
    };

    app()->instance(BillingProviderInterface::class, $this->fakeProvider);
    $this->service = app(BillingCustomerService::class);
});

it('F29-U1-CUST-01: findByTenant returns null when no customer exists', function (): void {
    $result = $this->service->findByTenant($this->tenant);

    expect($result)->toBeNull();
})->group('F29-U1-CUST');

it('F29-U1-CUST-02: ensureCustomer creates new customer when none exists', function (): void {
    $result = $this->service->ensureCustomer($this->tenant);

    expect($result->provider_customer_id)->toStartWith('cus_fake_');
    expect($result->provider)->toBe('stripe');
    expect($result->tenant_id)->toBe($this->tenant->id);
})->group('F29-U1-CUST');

it('F29-U1-CUST-03: ensureCustomer returns existing customer on second call', function (): void {
    $first = $this->service->ensureCustomer($this->tenant);
    $second = $this->service->ensureCustomer($this->tenant);

    expect($first->id)->toBe($second->id);
    expect($this->fakeProvider->callCount)->toBe(1);
})->group('F29-U1-CUST');

it('F29-U1-CUST-04: ensureCustomer persists billing_customers record', function (): void {
    $this->service->ensureCustomer($this->tenant);

    $this->assertDatabaseHas('billing_customers', [
        'tenant_id' => $this->tenant->id,
        'provider' => 'stripe',
    ]);
})->group('F29-U1-CUST');

it('F29-U1-CUST-05: tenant isolation — customer for A not visible to findByTenant(B)', function (): void {
    $this->service->ensureCustomer($this->tenant);

    $result = $this->service->findByTenant($this->otherTenant);

    expect($result)->toBeNull();
})->group('F29-U1-CUST');

it('F29-U1-CUST-06: provider error does not create bad local mapping', function (): void {
    $failingProvider = new class implements BillingProviderInterface
    {
        use FakeBillingProviderMethods;

        public function createCustomer(array $params): BillingCustomerData
        {
            throw new BillingProviderException('Provider down');
        }

        public function retrieveCustomer(string $providerCustomerId): BillingCustomerData
        {
            throw new RuntimeException('Not implemented');
        }

        public function validatePrice(string $priceId): bool
        {
            return true;
        }

        public function createCheckoutSession(array $params): CheckoutSessionData
        {
            throw new RuntimeException('Not implemented');
        }

        public function createPortalSession(array $params): PortalSessionData
        {
            throw new RuntimeException('Not implemented');
        }

        public function providerName(): string
        {
            return 'stripe';
        }
    };

    app()->instance(BillingProviderInterface::class, $failingProvider);
    $service = app(BillingCustomerService::class);

    try {
        $service->ensureCustomer($this->tenant);
    } catch (Throwable) {
        // expected
    }

    $this->assertDatabaseMissing('billing_customers', [
        'tenant_id' => $this->tenant->id,
        'provider' => 'stripe',
    ]);
})->group('F29-U1-CUST');

it('F29-U1-CUST-07: no PII stored in billing_customers', function (): void {
    $customer = $this->service->ensureCustomer($this->tenant);

    $this->assertDatabaseHas('billing_customers', [
        'tenant_id' => $this->tenant->id,
        'provider' => 'stripe',
    ]);

    $record = BillingCustomer::where('tenant_id', $this->tenant->id)->first();
    expect($record->provider_customer_id)->not->toBeEmpty();
    expect($record->getAttributes())->not->toHaveKey('email');
    expect($record->getAttributes())->not->toHaveKey('name');
})->group('F29-U1-CUST');

it('F29-U1-CUST-08: different tenants create independent customers', function (): void {
    $custA = $this->service->ensureCustomer($this->tenant);

    TenantContext::setId($this->otherTenant->id);
    $custB = $this->service->ensureCustomer($this->otherTenant);

    expect($custA->id)->not->toBe($custB->id);
    expect($custA->provider_customer_id)->not->toBe($custB->provider_customer_id);
})->group('F29-U1-CUST');
