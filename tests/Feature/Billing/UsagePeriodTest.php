<?php

declare(strict_types=1);

use App\Application\Billing\Services\UsageTrackingService;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Enums\UsageCategory;
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

it('BILL-PERIOD-01: start inclusive — record at period_start is counted', function (): void {
    $this->service->record(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 3,
        recordedAt: Carbon::parse('2026-08-01 00:00:00'),
    );

    $usage = $this->service->currentPeriodUsage($this->tenant, UsageCategory::Messages);
    $this->assertEquals(3, $usage);
})->group('BILL-PERIOD-01');

it('BILL-PERIOD-02: end exclusive — record at period_end is excluded', function (): void {
    $this->service->record(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 7,
        recordedAt: Carbon::parse('2026-09-01 00:00:00'),
    );

    $usage = $this->service->currentPeriodUsage($this->tenant, UsageCategory::Messages);
    $this->assertEquals(0, $usage);
})->group('BILL-PERIOD-02');

it('BILL-PERIOD-03: exact current period — mixed records', function (): void {
    $this->service->record(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 1,
        recordedAt: Carbon::parse('2026-08-01 00:00:00'),
    );
    $this->service->record(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 2,
        recordedAt: Carbon::parse('2026-08-31 23:59:59'),
    );
    $this->service->record(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 5,
        recordedAt: Carbon::parse('2026-09-01 00:00:00'),
    );

    $usage = $this->service->currentPeriodUsage($this->tenant, UsageCategory::Messages);
    $this->assertEquals(3, $usage);
})->group('BILL-PERIOD-03');

it('BILL-PERIOD-04: null-period fallback uses calendar month', function (): void {
    $subscription = Subscription::factory()->create([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => SubscriptionStatus::Active,
        'current_period_start' => null,
        'current_period_end' => null,
    ]);

    $this->service->record(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 4,
        recordedAt: now()->startOfMonth()->addDays(5),
    );

    $usage = $this->service->currentPeriodUsage($this->tenant, UsageCategory::Messages);
    $this->assertEquals(4, $usage);
})->group('BILL-PERIOD-04');

it('BILL-PERIOD-05: summary period boundaries match subscription', function (): void {
    $summary = $this->service->currentPeriodSummary($this->tenant);

    expect($summary->periodStart)->toBe('2026-08-01T00:00:00+00:00')
        ->and($summary->periodEnd)->toBe('2026-09-01T00:00:00+00:00');
})->group('BILL-PERIOD-05');

it('BILL-PERIOD-06: no off-by-one — second before end is included', function (): void {
    $this->service->record(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 1,
        recordedAt: Carbon::parse('2026-08-31 23:59:59'),
    );

    $usage = $this->service->currentPeriodUsage($this->tenant, UsageCategory::Messages);
    $this->assertEquals(1, $usage);
})->group('BILL-PERIOD-06');
