<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Enums\UsageCategory;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Models\SubscriptionItem;
use App\Domain\Billing\Models\UsageRecord;
use App\Domain\Tenants\Models\Tenant;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
});

/*
|--------------------------------------------------------------------------
| Billing Model Tests (FASE 23 U1)
|--------------------------------------------------------------------------
|
| BILL-DOM-01..20 — Domain invariants for billing tables.
| Corren en SQLite :memory:.
|
*/

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    TenantContext::setId($this->tenant->id);
});

it('BILL-DOM-01: Plan can be created via factory', function (): void {
    $plan = Plan::factory()->create();

    $this->assertNotNull($plan->id);
    $this->assertNotNull($plan->slug);
    $this->assertTrue($plan->is_active);
})->group('BILL-DOM-01');

it('BILL-DOM-02: Plan uses UUID primary key', function (): void {
    $plan = Plan::factory()->create();

    expect($plan->id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/')
        ->and(strlen($plan->id))->toBe(36);
})->group('BILL-DOM-02');

it('BILL-DOM-03: Plan is NOT tenant-scoped (no tenant_id)', function (): void {
    $plan = Plan::factory()->create();

    $this->assertArrayNotHasKey('tenant_id', $plan->getAttributes());
})->group('BILL-DOM-03');

it('BILL-DOM-04: Plan slug is unique', function (): void {
    Plan::factory()->create(['slug' => 'pro']);

    $this->expectException(QueryException::class);
    Plan::factory()->create(['slug' => 'pro']);
})->group('BILL-DOM-04');

it('BILL-DOM-05: Plan limits are cast to array', function (): void {
    $plan = Plan::factory()->create([
        'limits' => ['messages' => 1000, 'ai_tokens' => 50000],
    ]);

    expect($plan->limits)->toBeArray()
        ->and($plan->limits['messages'])->toBe(1000)
        ->and($plan->limits['ai_tokens'])->toBe(50000);
})->group('BILL-DOM-05');

it('BILL-DOM-06: Plan features are cast to array', function (): void {
    $plan = Plan::factory()->create([
        'features' => ['ai_enabled' => true],
    ]);

    expect($plan->features)->toBeArray()
        ->and($plan->features['ai_enabled'])->toBeTrue();
})->group('BILL-DOM-06');

it('BILL-DOM-07: Plan getLimit returns value for defined category', function (): void {
    $plan = Plan::factory()->create([
        'limits' => ['messages' => 5000],
    ]);

    expect($plan->getLimit('messages'))->toBe(5000);
})->group('BILL-DOM-07');

it('BILL-DOM-08: Plan getLimit returns null for undefined category', function (): void {
    $plan = Plan::factory()->create(['limits' => []]);

    expect($plan->getLimit('messages'))->toBeNull();
})->group('BILL-DOM-08');

it('BILL-DOM-09: Plan hasFeature returns true for enabled feature', function (): void {
    $plan = Plan::factory()->create([
        'features' => ['ai_enabled' => true],
    ]);

    expect($plan->hasFeature('ai_enabled'))->toBeTrue();
})->group('BILL-DOM-09');

it('BILL-DOM-10: Plan hasFeature returns false for disabled feature', function (): void {
    $plan = Plan::factory()->create([
        'features' => ['ai_enabled' => false],
    ]);

    expect($plan->hasFeature('ai_enabled'))->toBeFalse();
})->group('BILL-DOM-10');

it('BILL-DOM-11: Subscription can be created via factory', function (): void {
    $subscription = Subscription::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $this->assertNotNull($subscription->id);
    $this->assertEquals($this->tenant->id, $subscription->tenant_id);
    $this->assertEquals(SubscriptionStatus::Active, $subscription->status);
})->group('BILL-DOM-11');

it('BILL-DOM-12: Subscription tenant_id auto-assigned from TenantContext', function (): void {
    $subscription = Subscription::factory()->create([
        'tenant_id' => null,
    ]);

    // BelongsToTenant sets tenant_id from TenantContext
    expect($subscription->tenant_id)->toBe($this->tenant->id);
})->group('BILL-DOM-12');

it('BILL-DOM-13: Subscription belongs to plan', function (): void {
    $plan = Plan::factory()->create();
    $subscription = Subscription::factory()->create([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $plan->id,
    ]);

    expect($subscription->plan)->toBeInstanceOf(Plan::class)
        ->and($subscription->plan->id)->toBe($plan->id);
})->group('BILL-DOM-13');

it('BILL-DOM-14: Subscription belongs to tenant', function (): void {
    $subscription = Subscription::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    expect($subscription->tenant)->toBeInstanceOf(Tenant::class)
        ->and($subscription->tenant->id)->toBe($this->tenant->id);
})->group('BILL-DOM-14');

it('BILL-DOM-15: Subscription isActive reflects status', function (): void {
    $active = Subscription::factory()->create([
        'tenant_id' => $this->tenant->id,
        'status' => SubscriptionStatus::Active,
    ]);

    $cancelled = Subscription::factory()->create([
        'tenant_id' => $this->tenant->id,
        'status' => SubscriptionStatus::Cancelled,
    ]);

    expect($active->isActive())->toBeTrue()
        ->and($cancelled->isActive())->toBeFalse();
})->group('BILL-DOM-15');

it('BILL-DOM-16: SubscriptionItem can be created via factory', function (): void {
    $subscription = Subscription::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $item = SubscriptionItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'subscription_id' => $subscription->id,
        'category' => UsageCategory::Messages,
        'included_usage' => 5000,
    ]);

    $this->assertNotNull($item->id);
    expect($item->included_usage)->toBe(5000);
})->group('BILL-DOM-16');

it('BILL-DOM-17: SubscriptionItem unique per subscription+category', function (): void {
    $subscription = Subscription::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    SubscriptionItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'subscription_id' => $subscription->id,
        'category' => UsageCategory::Messages,
    ]);

    $this->expectException(QueryException::class);
    SubscriptionItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'subscription_id' => $subscription->id,
        'category' => UsageCategory::Messages,
    ]);
})->group('BILL-DOM-17');

it('BILL-DOM-18: UsageRecord can be created via factory', function (): void {
    $subscription = Subscription::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $record = UsageRecord::factory()->create([
        'tenant_id' => $this->tenant->id,
        'subscription_id' => $subscription->id,
        'category' => UsageCategory::Messages,
        'quantity' => 5,
    ]);

    $this->assertNotNull($record->id);
    expect($record->quantity)->toBe(5);
})->group('BILL-DOM-18');

it('BILL-DOM-19: UsageRecord metadata is cast to array', function (): void {
    $subscription = Subscription::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $record = UsageRecord::factory()->create([
        'tenant_id' => $this->tenant->id,
        'subscription_id' => $subscription->id,
        'metadata' => ['conversation_id' => 'test-123'],
    ]);

    expect($record->metadata)->toBeArray()
        ->and($record->metadata['conversation_id'])->toBe('test-123');
})->group('BILL-DOM-19');

it('BILL-DOM-20: Tenant has plan relationship', function (): void {
    $plan = Plan::factory()->create();
    $this->tenant->update(['plan_id' => $plan->id]);
    $this->tenant->refresh();

    expect($this->tenant->plan)->toBeInstanceOf(Plan::class)
        ->and($this->tenant->plan->id)->toBe($plan->id);
})->group('BILL-DOM-20');

it('BILL-DOM-21: Tenant plan_id nullable (no plan assigned)', function (): void {
    expect($this->tenant->plan_id)->toBeNull()
        ->and($this->tenant->plan)->toBeNull();
})->group('BILL-DOM-21');

it('BILL-DOM-22: Subscription metadata defaults to empty array', function (): void {
    $subscription = Subscription::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    expect($subscription->metadata)->toBe([]);
})->group('BILL-DOM-22');

it('BILL-DOM-23: UsageRecord recorded_at is cast to datetime', function (): void {
    $subscription = Subscription::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $record = UsageRecord::factory()->create([
        'tenant_id' => $this->tenant->id,
        'subscription_id' => $subscription->id,
        'recorded_at' => '2026-08-22 10:00:00',
    ]);

    expect($record->recorded_at)->toBeInstanceOf(Carbon::class);
})->group('BILL-DOM-23');

it('BILL-DOM-24: Plan is non-fillable for id', function (): void {
    $plan = Plan::factory()->create();

    $plan->fill(['id' => 'injected-id']);
    expect($plan->id)->not->toBe('injected-id');
})->group('BILL-DOM-24');

it('BILL-DOM-25: UsageRecord quantity is integer cast', function (): void {
    $subscription = Subscription::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $record = UsageRecord::factory()->create([
        'tenant_id' => $this->tenant->id,
        'subscription_id' => $subscription->id,
        'quantity' => 42,
    ]);

    expect($record->quantity)->toBeInt()->toBe(42);
})->group('BILL-DOM-25');
