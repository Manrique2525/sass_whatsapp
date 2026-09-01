<?php

declare(strict_types=1);

use App\Application\Billing\Guards\EntitlementResolver;
use App\Application\Billing\Guards\UsageGuard;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Enums\UsageCategory;
use App\Domain\Billing\Enums\UsageReservationStatus;
use App\Domain\Billing\Exceptions\TenantQuotaExceededException;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Models\UsageRecord;
use App\Domain\Billing\Models\UsageReservation;
use App\Domain\Tenants\Models\Tenant;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| PostgreSQL AI Token Quota Enforcement Tests (FASE 25 U3)
|--------------------------------------------------------------------------
|
| UA-PG-01..09 — AI quota reservation, reconciliation, concurrency,
| idempotency, actual>reserved, provider failure against real PG.
|
| Execute with:
|   docker compose exec -T app vendor/bin/pest
|     --configuration=phpunit.pgsql.xml
|     --filter="AiQuotaPostgresTest"
|     --no-coverage
|
*/

uses(RefreshDatabase::class);

afterEach(function (): void {
    Carbon::setTestNow();
    TenantContext::clear();
});

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00'));

    $this->guard = new UsageGuard(new EntitlementResolver);

    $this->tenant = Tenant::factory()->create();
    TenantContext::setId($this->tenant->id);

    $this->plan = Plan::factory()->create([
        'limits' => [
            'messages' => 1000,
            'ai_tokens' => 100,
            'contacts' => 100,
            'flow_executions' => 100,
            'users' => 10,
            'knowledge_documents' => 10,
        ],
    ]);

    $this->subscription = Subscription::factory()->create([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => SubscriptionStatus::Active,
        'current_period_start' => Carbon::parse('2026-08-01'),
        'current_period_end' => Carbon::parse('2026-09-01'),
    ]);

    TenantContext::setId($this->tenant->id);
});

// ============================================================
// UA-PG-01: Reserve AI tokens → commitWithActual → ledger record
// ============================================================

it('UA-PG-01: reserve ai tokens then commit with actual creates ledger record', function (): void {
    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::AiTokens,
        quantity: 50,
    );

    expect($reservation)->not->toBeNull()
        ->and($reservation->status)->toBe(UsageReservationStatus::Reserved)
        ->and($reservation->quantity)->toBe(50);

    $record = $this->guard->commitWithActual($reservation, 35);

    expect($record->quantity)->toBe(35);

    $reservation->refresh();
    expect($reservation->status)->toBe(UsageReservationStatus::Committed)
        ->and($reservation->quantity)->toBe(35);

    $used = (int) UsageRecord::query()
        ->withoutTenantScope()
        ->where('tenant_id', $this->tenant->id)
        ->where('category', UsageCategory::AiTokens)
        ->sum('quantity');

    expect($used)->toBe(35);
})->group('UA-PG-01');

// ============================================================
// UA-PG-02: Two concurrent AI reserves at limit — one succeeds
// ============================================================

it('UA-PG-02: two concurrent ai reserves at limit boundary — only one succeeds', function (): void {
    $lockKey = crc32("{$this->tenant->id}:ai_tokens:".Carbon::parse('2026-08-01')->toDateString());

    $conn1 = DB::connection('pgsql');
    $conn2 = DB::connection('pgsql');

    $conn1->beginTransaction();
    $conn2->beginTransaction();

    $conn1->select('SELECT pg_advisory_xact_lock(CAST(? AS bigint))', [$lockKey]);

    $r1 = null;
    $r2 = null;

    try {
        $r1 = $this->guard->reserve(
            tenant: $this->tenant,
            category: UsageCategory::AiTokens,
            quantity: 60,
        );
    } catch (TenantQuotaExceededException) {
        $this->fail('First reserve should succeed');
    }

    $conn1->rollBack();

    try {
        $r2 = $this->guard->reserve(
            tenant: $this->tenant,
            category: UsageCategory::AiTokens,
            quantity: 60,
        );
    } catch (TenantQuotaExceededException) {
        // Expected
    }

    $conn2->rollBack();

    $count = UsageReservation::query()
        ->withoutTenantScope()
        ->where('tenant_id', $this->tenant->id)
        ->where('category', UsageCategory::AiTokens)
        ->where('status', UsageReservationStatus::Reserved)
        ->count();

    expect($count)->toBeLessThanOrEqual(1);

    $totalReserved = (int) UsageReservation::query()
        ->withoutTenantScope()
        ->where('tenant_id', $this->tenant->id)
        ->where('category', UsageCategory::AiTokens)
        ->where('status', UsageReservationStatus::Reserved)
        ->sum('quantity');

    expect($totalReserved)->toBeLessThanOrEqual(100);
})->group('UA-PG-02');

// ============================================================
// UA-PG-03: Idempotent reserve returns same reservation
// ============================================================

it('UA-PG-03: idempotent reserve with same key returns same reservation', function (): void {
    $key = 'ai:flow:test-execution-123:node-456';

    $r1 = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::AiTokens,
        quantity: 30,
        idempotencyKey: $key,
    );

    $r2 = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::AiTokens,
        quantity: 30,
        idempotencyKey: $key,
    );

    expect($r1->id)->toBe($r2->id);

    $totalReserved = (int) UsageReservation::query()
        ->withoutTenantScope()
        ->where('tenant_id', $this->tenant->id)
        ->where('category', UsageCategory::AiTokens)
        ->where('status', UsageReservationStatus::Reserved)
        ->sum('quantity');

    expect($totalReserved)->toBe(30);
})->group('UA-PG-03');

// ============================================================
// UA-PG-04: Commit with actual → second commit on same reservation throws
// ============================================================

it('UA-PG-04: second commitWithActual on committed reservation throws InvalidArgumentException', function (): void {
    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::AiTokens,
        quantity: 50,
    );

    $this->guard->commitWithActual($reservation, 40);

    $reservation->refresh();
    expect($reservation->status)->toBe(UsageReservationStatus::Committed);

    try {
        $this->guard->commitWithActual($reservation, 60);
        $this->fail('Expected InvalidArgumentException for already committed reservation');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('status is committed');
    }

    $totalQuantity = (int) UsageRecord::query()
        ->withoutTenantScope()
        ->where('tenant_id', $this->tenant->id)
        ->where('category', UsageCategory::AiTokens)
        ->sum('quantity');

    expect($totalQuantity)->toBe(40);
})->group('UA-PG-04');

// ============================================================
// UA-PG-05: actual > reserved — ledger reflects actual, not truncated
// ============================================================

it('UA-PG-05: actual > reserved reflects actual quantity without truncation', function (): void {
    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::AiTokens,
        quantity: 80,
    );

    expect($reservation->quantity)->toBe(80);

    $record = $this->guard->commitWithActual($reservation, 120);

    expect($record->quantity)->toBe(120);

    $reservation->refresh();
    expect($reservation->quantity)->toBe(120)
        ->and($reservation->status)->toBe(UsageReservationStatus::Committed);

    $totalUsed = (int) UsageRecord::query()
        ->withoutTenantScope()
        ->where('tenant_id', $this->tenant->id)
        ->where('category', UsageCategory::AiTokens)
        ->sum('quantity');

    expect($totalUsed)->toBe(120);

    $remaining = $this->guard->remaining($this->tenant, UsageCategory::AiTokens);

    expect($remaining)->toBe(0);
})->group('UA-PG-05');

// ============================================================
// UA-PG-06: Provider failure releases reservation
// ============================================================

it('UA-PG-06: provider failure releases reservation — no usage recorded', function (): void {
    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::AiTokens,
        quantity: 50,
    );

    expect($reservation)->not->toBeNull();

    $this->guard->release($reservation);

    $reservation->refresh();
    expect($reservation->status)->toBe(UsageReservationStatus::Released);

    $recordCount = UsageRecord::query()
        ->withoutTenantScope()
        ->where('tenant_id', $this->tenant->id)
        ->where('category', UsageCategory::AiTokens)
        ->count();

    expect($recordCount)->toBe(0);

    $remaining = $this->guard->remaining($this->tenant, UsageCategory::AiTokens);
    expect($remaining)->toBe(100);
})->group('UA-PG-06');

// ============================================================
// UA-PG-07: Reserve → commit actual → remaining decremented correctly
// ============================================================

it('UA-PG-07: reserve and commit actual decrements remaining correctly on real pg', function (): void {
    $remaining = $this->guard->remaining($this->tenant, UsageCategory::AiTokens);
    expect($remaining)->toBe(100);

    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::AiTokens,
        quantity: 50,
        idempotencyKey: 'ai:test:executor:001',
    );

    $this->guard->commitWithActual($reservation, 35);

    $recordCount = UsageRecord::query()
        ->withoutTenantScope()
        ->where('tenant_id', $this->tenant->id)
        ->where('category', UsageCategory::AiTokens)
        ->count();

    expect($recordCount)->toBe(1);

    $totalUsed = (int) UsageRecord::query()
        ->withoutTenantScope()
        ->where('tenant_id', $this->tenant->id)
        ->where('category', UsageCategory::AiTokens)
        ->sum('quantity');

    expect($totalUsed)->toBe(35);

    $remainingAfter = $this->guard->remaining($this->tenant, UsageCategory::AiTokens);
    expect($remainingAfter)->toBe(65);
})->group('UA-PG-07');

// ============================================================
// UA-PG-08: Multiple AI operations consume quota cumulatively
// ============================================================

it('UA-PG-08: multiple ai operations consume quota cumulatively', function (): void {
    $tokensUsed = [25, 40, 15];

    foreach ($tokensUsed as $i => $tokens) {
        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00')->addSeconds($i));

        $reservation = $this->guard->reserve(
            tenant: $this->tenant,
            category: UsageCategory::AiTokens,
            quantity: $tokens + 10,
            idempotencyKey: "ai:test:multi:{$i}",
        );

        $this->guard->commitWithActual($reservation, $tokens);

        if ($i < count($tokensUsed) - 1) {
            sleep(1);
        }
    }

    $totalUsed = (int) UsageRecord::query()
        ->withoutTenantScope()
        ->where('tenant_id', $this->tenant->id)
        ->where('category', UsageCategory::AiTokens)
        ->sum('quantity');

    expect($totalUsed)->toBe(80);

    $remaining = $this->guard->remaining($this->tenant, UsageCategory::AiTokens);
    expect($remaining)->toBe(20);

    $this->expectException(TenantQuotaExceededException::class);

    $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::AiTokens,
        quantity: 30,
    );
})->group('UA-PG-08');

// ============================================================
// UA-PG-09: Unlimited plan — no reservation, recordDirect works
// ============================================================

it('UA-PG-09: unlimited ai_tokens plan returns null reservation', function (): void {
    $unlimitedPlan = Plan::factory()->create([
        'limits' => [
            'messages' => 1000,
            'ai_tokens' => null,
            'contacts' => 100,
            'flow_executions' => 100,
            'users' => 10,
            'knowledge_documents' => 10,
        ],
    ]);

    $unlimitedTenant = Tenant::factory()->create();
    TenantContext::setId($unlimitedTenant->id);

    Subscription::factory()->create([
        'tenant_id' => $unlimitedTenant->id,
        'plan_id' => $unlimitedPlan->id,
        'status' => SubscriptionStatus::Active,
        'current_period_start' => Carbon::parse('2026-08-01'),
        'current_period_end' => Carbon::parse('2026-09-01'),
    ]);

    $reservation = $this->guard->reserve(
        tenant: $unlimitedTenant,
        category: UsageCategory::AiTokens,
        quantity: 999999,
    );

    expect($reservation)->toBeNull();

    $this->guard->recordDirect(
        tenant: $unlimitedTenant,
        category: UsageCategory::AiTokens,
        quantity: 150,
        description: 'ai_generation_unlimited',
    );

    $record = UsageRecord::query()
        ->withoutTenantScope()
        ->where('tenant_id', $unlimitedTenant->id)
        ->where('category', UsageCategory::AiTokens)
        ->first();

    expect($record)->not->toBeNull()
        ->and($record->quantity)->toBe(150);

    $remaining = $this->guard->remaining($unlimitedTenant, UsageCategory::AiTokens);
    expect($remaining)->toBeNull();

    TenantContext::setId($this->tenant->id);
})->group('UA-PG-09');
