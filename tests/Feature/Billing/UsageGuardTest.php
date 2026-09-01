<?php

declare(strict_types=1);

use App\Application\Billing\Guards\EntitlementResolver;
use App\Application\Billing\Guards\UsageGuard;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Enums\UsageCategory;
use App\Domain\Billing\Enums\UsageReservationStatus;
use App\Domain\Billing\Exceptions\InvalidUsageQuantityException;
use App\Domain\Billing\Exceptions\SubscriptionNotFoundException;
use App\Domain\Billing\Exceptions\TenantQuotaExceededException;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Models\UsageRecord;
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

// ──────────────────────────────────────────────
// remaining()
// ──────────────────────────────────────────────

it('USG-U1-UNIT-01: remaining returns null for unlimited plan', function (): void {
    $plan = Plan::factory()->create([
        'limits' => [
            'messages' => null,
            'ai_tokens' => 5000,
            'contacts' => 50,
            'flow_executions' => 10,
            'users' => 3,
            'knowledge_documents' => 5,
        ],
    ]);
    $this->subscription->update(['plan_id' => $plan->id]);

    $remaining = $this->guard->remaining($this->tenant, UsageCategory::Messages);

    expect($remaining)->toBeNull();
})->group('USG-U1-UNIT-01');

it('USG-U1-UNIT-02: remaining returns 0 for blocked category', function (): void {
    $plan = Plan::factory()->create([
        'limits' => [
            'messages' => 0,
            'ai_tokens' => 5000,
            'contacts' => 50,
            'flow_executions' => 10,
            'users' => 3,
            'knowledge_documents' => 5,
        ],
    ]);
    $this->subscription->update(['plan_id' => $plan->id]);

    $remaining = $this->guard->remaining($this->tenant, UsageCategory::Messages);

    expect($remaining)->toBe(0);
})->group('USG-U1-UNIT-02');

it('USG-U1-UNIT-03: remaining returns limit minus usage', function (): void {
    UsageRecord::create([
        'tenant_id' => $this->tenant->id,
        'subscription_id' => $this->subscription->id,
        'category' => UsageCategory::Messages,
        'quantity' => 30,
        'metadata' => [],
        'recorded_at' => Carbon::parse('2026-08-15 12:00:00'),
    ]);

    $remaining = $this->guard->remaining($this->tenant, UsageCategory::Messages);

    expect($remaining)->toBe(70);
})->group('USG-U1-UNIT-03');

it('USG-U1-UNIT-04: remaining accounts for active reservations', function (): void {
    UsageReservation::create([
        'tenant_id' => $this->tenant->id,
        'subscription_id' => $this->subscription->id,
        'category' => UsageCategory::Messages,
        'period_start' => Carbon::parse('2026-08-01'),
        'period_end' => Carbon::parse('2026-09-01'),
        'quantity' => 20,
        'status' => UsageReservationStatus::Reserved,
        'expires_at' => now()->addSeconds(300),
        'reserved_at' => now(),
    ]);

    $remaining = $this->guard->remaining($this->tenant, UsageCategory::Messages);

    expect($remaining)->toBe(80);
})->group('USG-U1-UNIT-04');

it('USG-U1-UNIT-05: remaining throws SubscriptionNotFoundException when no subscription', function (): void {
    $tenantC = Tenant::factory()->create();
    TenantContext::setId($tenantC->id);

    $this->expectException(SubscriptionNotFoundException::class);

    $this->guard->remaining($tenantC, UsageCategory::Messages);
})->group('USG-U1-UNIT-05');

it('USG-U1-UNIT-06: remaining returns full limit when no usage', function (): void {
    $remaining = $this->guard->remaining($this->tenant, UsageCategory::Messages);

    expect($remaining)->toBe(100);
})->group('USG-U1-UNIT-06');

// ──────────────────────────────────────────────
// reserve() — entitlement
// ──────────────────────────────────────────────

it('USG-U1-UNIT-07: reserve succeeds for active subscription', function (): void {
    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 10,
    );

    expect($reservation)->toBeInstanceOf(UsageReservation::class)
        ->and($reservation->status)->toBe(UsageReservationStatus::Reserved)
        ->and($reservation->quantity)->toBe(10);
})->group('USG-U1-UNIT-07');

it('USG-U1-UNIT-08: reserve succeeds for past-due subscription', function (): void {
    $this->subscription->update(['status' => SubscriptionStatus::PastDue]);

    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 5,
    );

    expect($reservation->status)->toBe(UsageReservationStatus::Reserved);
})->group('USG-U1-UNIT-08');

it('USG-U1-UNIT-09: reserve throws SubscriptionNotFoundException for pending subscription (fail-closed)', function (): void {
    $this->subscription->update(['status' => SubscriptionStatus::Pending]);

    $this->expectException(SubscriptionNotFoundException::class);

    $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 5,
    );
})->group('USG-U1-UNIT-09');

it('USG-U1-UNIT-10: reserve throws SubscriptionNotFoundException for cancelled subscription (fail-closed)', function (): void {
    $this->subscription->update(['status' => SubscriptionStatus::Cancelled]);

    $this->expectException(SubscriptionNotFoundException::class);

    $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 5,
    );
})->group('USG-U1-UNIT-10');

it('USG-U1-UNIT-11: reserve throws SubscriptionNotFoundException when no subscription (fail-closed)', function (): void {
    $this->subscription->delete();

    $this->expectException(SubscriptionNotFoundException::class);

    $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 5,
    );
})->group('USG-U1-UNIT-11');

// ──────────────────────────────────────────────
// reserve() — quota check
// ──────────────────────────────────────────────

it('USG-U1-UNIT-12: reserve succeeds when under limit', function (): void {
    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 50,
    );

    expect($reservation->status)->toBe(UsageReservationStatus::Reserved);
})->group('USG-U1-UNIT-12');

it('USG-U1-UNIT-13: reserve fails when at exact limit', function (): void {
    UsageRecord::create([
        'tenant_id' => $this->tenant->id,
        'subscription_id' => $this->subscription->id,
        'category' => UsageCategory::Messages,
        'quantity' => 100,
        'metadata' => [],
        'recorded_at' => Carbon::parse('2026-08-15 12:00:00'),
    ]);

    $this->expectException(TenantQuotaExceededException::class);

    $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 1,
    );
})->group('USG-U1-UNIT-13');

it('USG-U1-UNIT-14: reserve succeeds with unlimited plan', function (): void {
    $plan = Plan::factory()->create([
        'limits' => [
            'messages' => null,
            'ai_tokens' => 5000,
            'contacts' => 50,
            'flow_executions' => 10,
            'users' => 3,
            'knowledge_documents' => 5,
        ],
    ]);
    $this->subscription->update(['plan_id' => $plan->id]);

    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 999999,
    );

    expect($reservation)->toBeNull();
})->group('USG-U1-UNIT-14');

it('USG-U1-UNIT-15: reserve fails for blocked category', function (): void {
    $plan = Plan::factory()->create([
        'limits' => [
            'messages' => 0,
            'ai_tokens' => 5000,
            'contacts' => 50,
            'flow_executions' => 10,
            'users' => 3,
            'knowledge_documents' => 5,
        ],
    ]);
    $this->subscription->update(['plan_id' => $plan->id]);

    $this->expectException(TenantQuotaExceededException::class);

    $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 1,
    );
})->group('USG-U1-UNIT-15');

// ──────────────────────────────────────────────
// reserve() — quantity validation
// ──────────────────────────────────────────────

it('USG-U1-UNIT-16: reserve rejects zero quantity', function (): void {
    $this->expectException(InvalidUsageQuantityException::class);

    $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 0,
    );
})->group('USG-U1-UNIT-16');

it('USG-U1-UNIT-17: reserve rejects negative quantity', function (): void {
    $this->expectException(InvalidUsageQuantityException::class);

    $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: -5,
    );
})->group('USG-U1-UNIT-17');

// ──────────────────────────────────────────────
// reserve() — period boundaries
// ──────────────────────────────────────────────

it('USG-U1-UNIT-18: reservation period matches subscription period', function (): void {
    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 5,
    );

    expect($reservation->period_start->toDateString())->toBe('2026-08-01')
        ->and($reservation->period_end->toDateString())->toBe('2026-09-01');
})->group('USG-U1-UNIT-18');

it('USG-U1-UNIT-19: reservation expires after TTL', function (): void {
    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 5,
        ttlSeconds: 60,
    );

    expect($reservation->expires_at->timestamp)->toBeGreaterThan(now()->timestamp);
})->group('USG-U1-UNIT-19');

// ──────────────────────────────────────────────
// commit()
// ──────────────────────────────────────────────

it('USG-U1-COMMIT-01: commit creates usage record', function (): void {
    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 10,
    );

    $usageRecord = $this->guard->commit($reservation);

    expect($usageRecord)->toBeInstanceOf(UsageRecord::class)
        ->and($usageRecord->quantity)->toBe(10)
        ->and($usageRecord->category)->toBe(UsageCategory::Messages)
        ->and($usageRecord->tenant_id)->toBe($this->tenant->id);
})->group('USG-U1-COMMIT-01');

it('USG-U1-COMMIT-02: commit updates reservation status', function (): void {
    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 5,
    );

    $this->guard->commit($reservation);

    $reservation->refresh();
    expect($reservation->status)->toBe(UsageReservationStatus::Committed)
        ->and($reservation->committed_at)->not->toBeNull();
})->group('USG-U1-COMMIT-02');

it('USG-U1-COMMIT-03: commit rejects committed reservation', function (): void {
    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 5,
    );
    $this->guard->commit($reservation);

    $this->expectException(InvalidArgumentException::class);

    $this->guard->commit($reservation);
})->group('USG-U1-COMMIT-03');

it('USG-U1-COMMIT-04: commit rejects expired reservation', function (): void {
    $reservation = UsageReservation::factory()->create([
        'tenant_id' => $this->tenant->id,
        'subscription_id' => $this->subscription->id,
        'category' => UsageCategory::Messages,
        'status' => UsageReservationStatus::Reserved,
        'expires_at' => now()->subMinute(),
    ]);

    $this->expectException(InvalidArgumentException::class);

    $this->guard->commit($reservation);
})->group('USG-U1-COMMIT-04');

// ──────────────────────────────────────────────
// release()
// ──────────────────────────────────────────────

it('USG-U1-RELEASE-01: release updates status to released', function (): void {
    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 5,
    );

    $this->guard->release($reservation);

    $reservation->refresh();
    expect($reservation->status)->toBe(UsageReservationStatus::Released)
        ->and($reservation->released_at)->not->toBeNull();
})->group('USG-U1-RELEASE-01');

it('USG-U1-RELEASE-02: release does not create usage record', function (): void {
    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 5,
    );

    $this->guard->release($reservation);

    $usageCount = UsageRecord::query()
        ->where('tenant_id', $this->tenant->id)
        ->where('category', UsageCategory::Messages)
        ->count();

    expect($usageCount)->toBe(0);
})->group('USG-U1-RELEASE-02');

it('USG-U1-RELEASE-03: release rejects committed reservation', function (): void {
    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 5,
    );
    $this->guard->commit($reservation);

    $this->expectException(InvalidArgumentException::class);

    $this->guard->release($reservation);
})->group('USG-U1-RELEASE-03');
