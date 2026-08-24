<?php

declare(strict_types=1);

use App\Application\Billing\Guards\EntitlementResolver;
use App\Application\Billing\Guards\UsageGuard;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Enums\UsageCategory;
use App\Domain\Billing\Enums\UsageReservationStatus;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Models\UsageReservation;
use App\Domain\Tenants\Models\Tenant;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
});

beforeEach(function (): void {
    $this->guard = new UsageGuard(new EntitlementResolver);

    $this->tenant = Tenant::factory()->create();
    TenantContext::setId($this->tenant->id);

    $this->plan = Plan::factory()->create([
        'limits' => [
            'messages' => 100,
            'ai_tokens' => 5000,
            'contacts' => 50,
            'flow_executions' => 10,
            'users' => 3,
            'knowledge_documents' => 5,
        ],
    ]);

    $this->subscription = Subscription::factory()->create([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => SubscriptionStatus::Active,
        'current_period_start' => Carbon::parse('2026-08-01'),
        'current_period_end' => Carbon::parse('2026-09-01'),
    ]);
});

it('USG-U1-IDEM-01: same idempotency key returns same reservation', function (): void {
    $r1 = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 10,
        idempotencyKey: 'key-001',
    );

    $r2 = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 10,
        idempotencyKey: 'key-001',
    );

    expect($r1->id)->toBe($r2->id)
        ->and($r1->quantity)->toBe(10);
})->group('USG-U1-IDEM-01');

it('USG-U1-IDEM-02: committed reservation is idempotent on retry', function (): void {
    $r1 = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 10,
        idempotencyKey: 'key-002',
    );
    $this->guard->commit($r1);

    $r2 = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 10,
        idempotencyKey: 'key-002',
    );

    expect($r2->id)->toBe($r1->id)
        ->and($r2->status)->toBe(UsageReservationStatus::Committed);
})->group('USG-U1-IDEM-02');

it('USG-U1-IDEM-03: released reservation allows new reserve with same key', function (): void {
    $r1 = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 10,
        idempotencyKey: 'key-003',
    );
    $this->guard->release($r1);

    $r2 = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 10,
        idempotencyKey: 'key-003',
    );

    expect($r2->id)->not->toBe($r1->id)
        ->and($r2->status)->toBe(UsageReservationStatus::Reserved);
})->group('USG-U1-IDEM-03');

it('USG-U1-IDEM-04: expired reservation allows new reserve with same key', function (): void {
    $r1 = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 10,
        idempotencyKey: 'key-004',
        ttlSeconds: 1,
    );

    // Simulate expiry by setting expires_at to past
    $r1->update(['expires_at' => now()->subMinute()]);

    $r2 = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 10,
        idempotencyKey: 'key-004',
    );

    expect($r2->id)->not->toBe($r1->id);
})->group('USG-U1-IDEM-04');

it('USG-U1-IDEM-05: null idempotency key creates new reservation each time', function (): void {
    $r1 = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 5,
    );

    $r2 = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 5,
    );

    expect($r1->id)->not->toBe($r2->id);
})->group('USG-U1-IDEM-05');

it('USG-U1-IDEM-06: different idempotency keys create different reservations', function (): void {
    $r1 = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 5,
        idempotencyKey: 'key-a',
    );

    $r2 = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 5,
        idempotencyKey: 'key-b',
    );

    expect($r1->id)->not->toBe($r2->id);
})->group('USG-U1-IDEM-06');
