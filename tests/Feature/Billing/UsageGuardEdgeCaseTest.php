<?php

declare(strict_types=1);

use App\Application\Billing\Guards\EntitlementResolver;
use App\Application\Billing\Guards\UsageGuard;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Enums\UsageCategory;
use App\Domain\Billing\Enums\UsageReservationStatus;
use App\Domain\Billing\Exceptions\InvalidUsageQuantityException;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Models\UsageRecord;
use App\Domain\Tenants\Models\Tenant;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| UsageGuard Edge-Case Tests (FASE 26 U2 Step 36)
|--------------------------------------------------------------------------
|
| Verifies commit/release/commitWithActual lifecycle edge cases on SQLite.
| These complement the PG concurrency tests (UA-COMMIT-*) with single-
| process behavioral assertions.
|
*/

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
// UA-EDGE-01: commit() once succeeds
// ──────────────────────────────────────────────

it('UA-EDGE-01: commit on reserved reservation succeeds and creates usage record', function (): void {
    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 10,
    );

    expect($reservation->status)->toBe(UsageReservationStatus::Reserved);

    $record = $this->guard->commit($reservation);

    expect($record)->toBeInstanceOf(UsageRecord::class)
        ->and($record->quantity)->toBe(10)
        ->and($record->category)->toBe(UsageCategory::Messages)
        ->and($record->metadata['reservation_id'])->toBe($reservation->id);

    $reservation->refresh();
    expect($reservation->status)->toBe(UsageReservationStatus::Committed)
        ->and($reservation->committed_at)->not->toBeNull();
})->group('UA-EDGE-01');

// ──────────────────────────────────────────────
// UA-EDGE-02: commit() twice on same reservation throws
// ──────────────────────────────────────────────

it('UA-EDGE-02: second commit on same reservation throws InvalidArgumentException', function (): void {
    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 10,
    );

    $this->guard->commit($reservation);

    try {
        $this->guard->commit($reservation);
        $this->fail('Expected exception on second commit');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('committed');
    }
})->group('UA-EDGE-02');

// ──────────────────────────────────────────────
// UA-EDGE-03: release() once succeeds
// ──────────────────────────────────────────────

it('UA-EDGE-03: release on reserved reservation succeeds', function (): void {
    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 10,
    );

    $this->guard->release($reservation);

    $reservation->refresh();
    expect($reservation->status)->toBe(UsageReservationStatus::Released)
        ->and($reservation->released_at)->not->toBeNull();

    $usageCount = UsageRecord::query()
        ->withoutTenantScope()
        ->where('tenant_id', $this->tenant->id)
        ->where('category', UsageCategory::Messages)
        ->count();

    expect($usageCount)->toBe(0);
})->group('UA-EDGE-03');

// ──────────────────────────────────────────────
// UA-EDGE-04: release() twice on same reservation throws
// ──────────────────────────────────────────────

it('UA-EDGE-04: second release on same reservation throws InvalidArgumentException', function (): void {
    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 10,
    );

    $this->guard->release($reservation);

    try {
        $this->guard->release($reservation);
        $this->fail('Expected exception on second release');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('released');
    }
})->group('UA-EDGE-04');

// ──────────────────────────────────────────────
// UA-EDGE-05: release after commit throws
// ──────────────────────────────────────────────

it('UA-EDGE-05: release after commit throws InvalidArgumentException', function (): void {
    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 10,
    );

    $this->guard->commit($reservation);

    try {
        $this->guard->release($reservation);
        $this->fail('Expected exception on release after commit');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('committed');
    }
})->group('UA-EDGE-05');

// ──────────────────────────────────────────────
// UA-EDGE-06: commit after release throws
// ──────────────────────────────────────────────

it('UA-EDGE-06: commit after release throws InvalidArgumentException', function (): void {
    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 10,
    );

    $this->guard->release($reservation);

    try {
        $this->guard->commit($reservation);
        $this->fail('Expected exception on commit after release');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('released');
    }
})->group('UA-EDGE-06');

// ──────────────────────────────────────────────
// UA-EDGE-07: commitWithActual with actual < reserved
// ──────────────────────────────────────────────

it('UA-EDGE-07: commitWithActual records actual quantity when less than reserved', function (): void {
    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::AiTokens,
        quantity: 100,
    );

    $record = $this->guard->commitWithActual($reservation, 42);

    expect($record->quantity)->toBe(42);

    $reservation->refresh();
    expect($reservation->quantity)->toBe(42)
        ->and($reservation->status)->toBe(UsageReservationStatus::Committed);
})->group('UA-EDGE-07');

// ──────────────────────────────────────────────
// UA-EDGE-08: commitWithActual with actual > reserved
// ──────────────────────────────────────────────

it('UA-EDGE-08: commitWithActual records actual quantity when greater than reserved', function (): void {
    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::AiTokens,
        quantity: 100,
    );

    $record = $this->guard->commitWithActual($reservation, 250);

    expect($record->quantity)->toBe(250);

    $reservation->refresh();
    expect($reservation->quantity)->toBe(250)
        ->and($reservation->status)->toBe(UsageReservationStatus::Committed);
})->group('UA-EDGE-08');

// ──────────────────────────────────────────────
// UA-EDGE-09: commitWithActual with actual = reserved
// ──────────────────────────────────────────────

it('UA-EDGE-09: commitWithActual records actual quantity equal to reserved', function (): void {
    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::AiTokens,
        quantity: 100,
    );

    $record = $this->guard->commitWithActual($reservation, 100);

    expect($record->quantity)->toBe(100);

    $reservation->refresh();
    expect($reservation->quantity)->toBe(100)
        ->and($reservation->status)->toBe(UsageReservationStatus::Committed);
})->group('UA-EDGE-09');

// ──────────────────────────────────────────────
// UA-EDGE-10: commitWithActual with zero throws
// ──────────────────────────────────────────────

it('UA-EDGE-10: commitWithActual with actualQuantity=0 throws InvalidArgumentException', function (): void {
    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::AiTokens,
        quantity: 100,
    );

    try {
        $this->guard->commitWithActual($reservation, 0);
        $this->fail('Expected exception for zero actual quantity');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('positive');
    }
})->group('UA-EDGE-10');

// ──────────────────────────────────────────────
// UA-EDGE-11: recordDirect creates a usage record
// ──────────────────────────────────────────────

it('UA-EDGE-11: recordDirect creates usage record without reservation', function (): void {
    $record = $this->guard->recordDirect(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 5,
    );

    expect($record->quantity)->toBe(5)
        ->and($record->category)->toBe(UsageCategory::Messages)
        ->and($record->metadata)->toBe([]);

    $usageCount = UsageRecord::query()
        ->withoutTenantScope()
        ->where('tenant_id', $this->tenant->id)
        ->where('category', UsageCategory::Messages)
        ->count();

    expect($usageCount)->toBe(1);
})->group('UA-EDGE-11');

// ──────────────────────────────────────────────
// UA-EDGE-12: recordDirect with zero throws
// ──────────────────────────────────────────────

it('UA-EDGE-12: recordDirect with quantity=0 throws InvalidUsageQuantityException', function (): void {
    try {
        $this->guard->recordDirect(
            tenant: $this->tenant,
            category: UsageCategory::Messages,
            quantity: 0,
        );
        $this->fail('Expected exception for zero quantity');
    } catch (InvalidUsageQuantityException $e) {
        expect($e->getMessage())->toContain('positive');
    }
})->group('UA-EDGE-12');

// ──────────────────────────────────────────────
// UA-EDGE-13: remaining decrements after commit
// ──────────────────────────────────────────────

it('UA-EDGE-13: remaining decreases after commit', function (): void {
    $remainingBefore = $this->guard->remaining($this->tenant, UsageCategory::Messages);
    expect($remainingBefore)->toBe(100);

    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 30,
    );

    $remainingDuringReservation = $this->guard->remaining($this->tenant, UsageCategory::Messages);
    expect($remainingDuringReservation)->toBe(70);

    $this->guard->commit($reservation);

    $remainingAfter = $this->guard->remaining($this->tenant, UsageCategory::Messages);
    expect($remainingAfter)->toBe(70);
})->group('UA-EDGE-13');

// ──────────────────────────────────────────────
// UA-EDGE-14: remaining recovers after release
// ──────────────────────────────────────────────

it('UA-EDGE-14: remaining recovers after release', function (): void {
    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 50,
    );

    $remainingDuring = $this->guard->remaining($this->tenant, UsageCategory::Messages);
    expect($remainingDuring)->toBe(50);

    $this->guard->release($reservation);

    $remainingAfter = $this->guard->remaining($this->tenant, UsageCategory::Messages);
    expect($remainingAfter)->toBe(100);
})->group('UA-EDGE-14');

// ──────────────────────────────────────────────
// UA-EDGE-15: multiple reservations accumulate in remaining
// ──────────────────────────────────────────────

it('UA-EDGE-15: multiple active reservations reduce remaining cumulatively', function (): void {
    $r1 = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 20,
    );

    $r2 = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 30,
    );

    $remaining = $this->guard->remaining($this->tenant, UsageCategory::Messages);
    expect($remaining)->toBe(50);

    $this->guard->commit($r1);

    $remainingAfterOne = $this->guard->remaining($this->tenant, UsageCategory::Messages);
    expect($remainingAfterOne)->toBe(50);

    Carbon::setTestNow(now()->addSecond());

    $this->guard->commit($r2);

    $remainingAfterBoth = $this->guard->remaining($this->tenant, UsageCategory::Messages);
    expect($remainingAfterBoth)->toBe(50);
})->group('UA-EDGE-15');
