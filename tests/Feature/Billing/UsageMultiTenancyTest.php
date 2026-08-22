<?php

declare(strict_types=1);

use App\Application\Billing\Services\UsageTrackingService;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Enums\UsageCategory;
use App\Domain\Billing\Exceptions\SubscriptionNotFoundException;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Tenants\Models\Tenant;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
});

beforeEach(function (): void {
    $this->service = new UsageTrackingService;

    $this->tenantA = Tenant::factory()->create();
    $this->tenantB = Tenant::factory()->create();

    $this->planA = Plan::factory()->create([
        'limits' => [
            'messages' => 100,
            'ai_tokens' => 5000,
            'contacts' => 50,
            'flow_executions' => 10,
            'users' => 3,
            'knowledge_documents' => 5,
        ],
    ]);

    $this->planB = Plan::factory()->create([
        'limits' => [
            'messages' => 200,
            'ai_tokens' => 10000,
            'contacts' => 100,
            'flow_executions' => 20,
            'users' => 5,
            'knowledge_documents' => 10,
        ],
    ]);

    TenantContext::setId($this->tenantA->id);
    $this->subscriptionA = Subscription::factory()->create([
        'tenant_id' => $this->tenantA->id,
        'plan_id' => $this->planA->id,
        'status' => SubscriptionStatus::Active,
        'current_period_start' => Carbon::parse('2026-08-01'),
        'current_period_end' => Carbon::parse('2026-09-01'),
    ]);

    TenantContext::setId($this->tenantB->id);
    $this->subscriptionB = Subscription::factory()->create([
        'tenant_id' => $this->tenantB->id,
        'plan_id' => $this->planB->id,
        'status' => SubscriptionStatus::Active,
        'current_period_start' => Carbon::parse('2026-08-01'),
        'current_period_end' => Carbon::parse('2026-09-01'),
    ]);

    TenantContext::clear();
});

it('BILL-USG-MT-01: tenant A usage excludes tenant B', function (): void {
    TenantContext::setId($this->tenantA->id);
    $this->service->record(
        tenant: $this->tenantA,
        category: UsageCategory::Messages,
        quantity: 10,
    );

    TenantContext::setId($this->tenantB->id);
    $this->service->record(
        tenant: $this->tenantB,
        category: UsageCategory::Messages,
        quantity: 20,
    );

    TenantContext::setId($this->tenantA->id);
    $usageA = $this->service->currentPeriodUsage($this->tenantA, UsageCategory::Messages);

    TenantContext::setId($this->tenantB->id);
    $usageB = $this->service->currentPeriodUsage($this->tenantB, UsageCategory::Messages);

    $this->assertEquals(10, $usageA);
    $this->assertEquals(20, $usageB);
})->group('BILL-USG-MT-01');

it('BILL-USG-MT-02: tenant A cannot attach usage to subscription B', function (): void {
    TenantContext::setId($this->tenantA->id);
    $record = $this->service->record(
        tenant: $this->tenantA,
        category: UsageCategory::Messages,
    );

    $this->assertEquals($this->subscriptionA->id, $record->subscription_id);
    $this->assertNotEquals($this->subscriptionB->id, $record->subscription_id);
})->group('BILL-USG-MT-02');

it('BILL-USG-MT-03: same category A/B independent', function (): void {
    TenantContext::setId($this->tenantA->id);
    $this->service->record(
        tenant: $this->tenantA,
        category: UsageCategory::Messages,
        quantity: 5,
    );

    TenantContext::setId($this->tenantB->id);
    $this->service->record(
        tenant: $this->tenantB,
        category: UsageCategory::Messages,
        quantity: 15,
    );

    TenantContext::setId($this->tenantA->id);
    $summaryA = $this->service->currentPeriodSummary($this->tenantA);

    TenantContext::setId($this->tenantB->id);
    $summaryB = $this->service->currentPeriodSummary($this->tenantB);

    $this->assertEquals(5, $summaryA->categories['messages']->used);
    $this->assertEquals(100, $summaryA->categories['messages']->limit);
    $this->assertEquals(15, $summaryB->categories['messages']->used);
    $this->assertEquals(200, $summaryB->categories['messages']->limit);
})->group('BILL-USG-MT-03');

it('BILL-USG-MT-04: TenantContext sequential A then B is safe', function (): void {
    TenantContext::setId($this->tenantA->id);
    $this->service->record(
        tenant: $this->tenantA,
        category: UsageCategory::Contacts,
        quantity: 1,
    );

    TenantContext::setId($this->tenantB->id);
    $this->service->record(
        tenant: $this->tenantB,
        category: UsageCategory::Contacts,
        quantity: 2,
    );

    $usageA = $this->service->currentPeriodUsage($this->tenantA, UsageCategory::Contacts);
    $usageB = $this->service->currentPeriodUsage($this->tenantB, UsageCategory::Contacts);

    $this->assertEquals(1, $usageA);
    $this->assertEquals(2, $usageB);
})->group('BILL-USG-MT-04');

it('BILL-USG-MT-05: tenant_id in metadata cannot escape scope', function (): void {
    TenantContext::setId($this->tenantA->id);
    $record = $this->service->record(
        tenant: $this->tenantA,
        category: UsageCategory::Messages,
        metadata: ['tenant_id' => $this->tenantB->id],
    );

    expect($record->metadata)->toBe([])
        ->and($record->tenant_id)->toBe($this->tenantA->id);
})->group('BILL-USG-MT-05');

it('BILL-USG-MT-06: cross-tenant subscription ID fails closed', function (): void {
    TenantContext::setId($this->tenantA->id);

    $usage = $this->service->currentPeriodUsage($this->tenantA, UsageCategory::Messages);
    $this->assertEquals(0, $usage);
})->group('BILL-USG-MT-06');

it('BILL-USG-SEC-01: tenant_id is server-derived, never from caller', function (): void {
    TenantContext::setId($this->tenantA->id);

    $record = $this->service->record(
        tenant: $this->tenantA,
        category: UsageCategory::Messages,
    );

    $this->assertEquals($this->tenantA->id, $record->tenant_id);
})->group('BILL-USG-SEC-01');

it('BILL-USG-SEC-02: category is enum only', function (): void {
    TenantContext::setId($this->tenantA->id);

    $record = $this->service->record(
        tenant: $this->tenantA,
        category: UsageCategory::Messages,
    );

    $this->assertInstanceOf(UsageCategory::class, $record->category);
})->group('BILL-USG-SEC-02');

it('BILL-USG-SEC-03: metadata whitelist strips PII', function (): void {
    TenantContext::setId($this->tenantA->id);

    $record = $this->service->record(
        tenant: $this->tenantA,
        category: UsageCategory::Messages,
        metadata: [
            'conversation_id' => 'safe',
            'phone' => '+1234567890',
            'email' => 'user@example.com',
            'contact_name' => 'John Doe',
            'message_body' => 'Hello',
            'api_key' => 'sk_test_123',
        ],
    );

    expect($record->metadata)->toBe(['conversation_id' => 'safe']);
})->group('BILL-USG-SEC-03');

it('BILL-USG-SEC-04: no PII in summary response', function (): void {
    TenantContext::setId($this->tenantA->id);

    $this->service->record(
        tenant: $this->tenantA,
        category: UsageCategory::Messages,
        quantity: 5,
    );

    $summary = $this->service->currentPeriodSummary($this->tenantA);
    $json = json_encode($summary);

    expect($json)->not->toContain('phone')
        ->and($json)->not->toContain('email')
        ->and($json)->not->toContain('password')
        ->and($json)->not->toContain('secret')
        ->and($json)->not->toContain('api_key');
})->group('BILL-USG-SEC-04');

it('BILL-USG-SEC-05: no API keys or secrets in service', function (): void {
    $reflection = new ReflectionClass($this->service);
    $methods = array_map(
        fn (ReflectionMethod $m) => $m->getName(),
        $reflection->getMethods(ReflectionMethod::IS_PRIVATE),
    );

    $source = file_get_contents(base_path('app/Application/Billing/Services/UsageTrackingService.php'));

    expect($source)->not->toContain('api_key')
        ->and($source)->not->toContain('secret')
        ->and($source)->not->toContain('stripe')
        ->and($source)->not->toContain('sk_live')
        ->and($source)->not->toContain('sk_test');
})->group('BILL-USG-SEC-05');

it('BILL-USG-SEC-06: no update or delete methods on service', function (): void {
    $methods = get_class_methods($this->service);

    expect($methods)->not->toContain('updateUsage')
        ->and($methods)->not->toContain('deleteUsage')
        ->and($methods)->not->toContain('resetUsage');
})->group('BILL-USG-SEC-06');

it('BILL-USG-SEC-07: no subscription found throws exception', function (): void {
    $tenantC = Tenant::factory()->create();
    TenantContext::setId($tenantC->id);

    $this->expectException(SubscriptionNotFoundException::class);

    $this->service->record(
        tenant: $tenantC,
        category: UsageCategory::Messages,
    );
})->group('BILL-USG-SEC-07');

it('BILL-USG-CONC-01: concurrent inserts produce correct SUM', function (): void {
    TenantContext::setId($this->tenantA->id);

    for ($i = 0; $i < 10; $i++) {
        $this->service->record(
            tenant: $this->tenantA,
            category: UsageCategory::Messages,
            quantity: 1,
            recordedAt: now()->addSeconds($i),
        );
    }

    $usage = $this->service->currentPeriodUsage($this->tenantA, UsageCategory::Messages);
    $this->assertEquals(10, $usage);
})->group('BILL-USG-CONC-01');
