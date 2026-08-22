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

it('BILL-USG-14: summary returns all categories with used/limit/remaining', function (): void {
    $this->service->record(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 30,
    );
    $this->service->record(
        tenant: $this->tenant,
        category: UsageCategory::AiTokens,
        quantity: 1200,
    );

    $summary = $this->service->currentPeriodSummary($this->tenant);

    $this->assertEquals($this->subscription->id, $summary->subscriptionId);
    $this->assertCount(6, $summary->categories);

    expect($summary->categories['messages']->used)->toBe(30)
        ->and($summary->categories['messages']->limit)->toBe(100)
        ->and($summary->categories['messages']->remaining)->toBe(70)
        ->and($summary->categories['ai_tokens']->used)->toBe(1200)
        ->and($summary->categories['ai_tokens']->limit)->toBe(5000)
        ->and($summary->categories['ai_tokens']->remaining)->toBe(3800);
})->group('BILL-USG-14');

it('BILL-USG-16: history ordered by recorded_at DESC, id DESC', function (): void {
    $this->service->record(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 1,
        recordedAt: Carbon::parse('2026-08-01 10:00:00'),
    );
    $this->service->record(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 2,
        recordedAt: Carbon::parse('2026-08-01 12:00:00'),
    );
    $this->service->record(
        tenant: $this->tenant,
        category: UsageCategory::AiTokens,
        quantity: 100,
        recordedAt: Carbon::parse('2026-08-01 11:00:00'),
    );

    $history = $this->service->history($this->tenant);
    $records = $history->getCollection()->all();

    $this->assertCount(3, $records);
    $this->assertEquals(2, $records[0]->quantity);
    $this->assertEquals(100, $records[1]->quantity);
    $this->assertEquals(1, $records[2]->quantity);
})->group('BILL-USG-16');

it('BILL-USG-17: history category filter works', function (): void {
    $this->service->record(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 1,
    );
    $this->service->record(
        tenant: $this->tenant,
        category: UsageCategory::AiTokens,
        quantity: 500,
    );

    $history = $this->service->history($this->tenant, [
        'category' => UsageCategory::Messages,
    ]);

    $this->assertEquals(1, $history->total());
    $this->assertEquals(UsageCategory::Messages, $history->getCollection()->first()->category);
})->group('BILL-USG-17');

it('BILL-USG-18: history date filter works', function (): void {
    $this->service->record(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 1,
        recordedAt: Carbon::parse('2026-08-01'),
    );
    $this->service->record(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 2,
        recordedAt: Carbon::parse('2026-08-15'),
    );
    $this->service->record(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 3,
        recordedAt: Carbon::parse('2026-08-30'),
    );

    $history = $this->service->history($this->tenant, [
        'from' => Carbon::parse('2026-08-10'),
        'to' => Carbon::parse('2026-08-25'),
    ]);

    $this->assertEquals(1, $history->total());
    $this->assertEquals(2, $history->getCollection()->first()->quantity);
})->group('BILL-USG-18');
