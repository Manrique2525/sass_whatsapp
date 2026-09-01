<?php

declare(strict_types=1);

use App\Application\Billing\Services\UsageTrackingService;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Enums\UsageCategory;
use App\Domain\Billing\Exceptions\InvalidUsageQuantityException;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Models\UsageRecord;
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

it('BILL-USG-01: record creates usage with quantity 1', function (): void {
    $record = $this->service->record(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
    );

    $this->assertInstanceOf(UsageRecord::class, $record);
    $this->assertEquals($this->tenant->id, $record->tenant_id);
    $this->assertEquals($this->subscription->id, $record->subscription_id);
    $this->assertEquals(UsageCategory::Messages, $record->category);
    $this->assertEquals(1, $record->quantity);
    $this->assertNotNull($record->recorded_at);
})->group('BILL-USG-01');

it('BILL-USG-02: record creates usage with custom quantity', function (): void {
    $record = $this->service->record(
        tenant: $this->tenant,
        category: UsageCategory::AiTokens,
        quantity: 1500,
    );

    $this->assertEquals(1500, $record->quantity);
    $this->assertEquals(UsageCategory::AiTokens, $record->category);
})->group('BILL-USG-02');

it('BILL-USG-03: record rejects zero quantity', function (): void {
    $this->expectException(InvalidUsageQuantityException::class);

    $this->service->record(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 0,
    );
})->group('BILL-USG-03');

it('BILL-USG-04: record rejects negative quantity', function (): void {
    $this->expectException(InvalidUsageQuantityException::class);

    $this->service->record(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: -5,
    );
})->group('BILL-USG-04');

it('BILL-USG-05: record stores category as enum', function (): void {
    $record = $this->service->record(
        tenant: $this->tenant,
        category: UsageCategory::FlowExecutions,
    );

    $this->assertEquals(UsageCategory::FlowExecutions, $record->category);
    $this->assertEquals('flow_executions', $record->getRawOriginal('category'));
})->group('BILL-USG-05');

it('BILL-USG-06: record uses provided recorded_at', function (): void {
    $date = Carbon::create(2026, 8, 15, 10, 30, 0);

    $record = $this->service->record(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        recordedAt: $date,
    );

    $this->assertTrue($record->recorded_at->eq($date));
})->group('BILL-USG-06');

it('BILL-USG-07: record sanitizes metadata to whitelist only', function (): void {
    $record = $this->service->record(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        metadata: [
            'conversation_id' => 'conv-123',
            'phone' => '+1234567890',
            'email' => 'test@example.com',
            'message_id' => 'msg-456',
        ],
    );

    expect($record->metadata)->toBe([
        'conversation_id' => 'conv-123',
        'message_id' => 'msg-456',
    ]);
})->group('BILL-USG-07');

it('BILL-USG-08: record resolves active subscription', function (): void {
    $record = $this->service->record(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
    );

    $this->assertEquals($this->subscription->id, $record->subscription_id);
})->group('BILL-USG-08');

it('BILL-USG-13: currentPeriodUsage returns 0 when empty', function (): void {
    $usage = $this->service->currentPeriodUsage($this->tenant, UsageCategory::Messages);

    $this->assertEquals(0, $usage);
})->group('BILL-USG-13');

it('BILL-USG-15: unlimited semantics — null limit in summary', function (): void {
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

    $this->service->record(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 999,
        recordedAt: Carbon::parse('2026-08-15 12:00:00'),
    );

    $summary = $this->service->currentPeriodSummary($this->tenant);

    expect($summary->categories['messages']->limit)->toBeNull()
        ->and($summary->categories['messages']->remaining)->toBeNull()
        ->and($summary->categories['messages']->used)->toBe(999);
})->group('BILL-USG-15');

it('BILL-USG-19: no update method exists on service', function (): void {
    $methods = get_class_methods($this->service);

    expect($methods)->not->toContain('updateUsage')
        ->and($methods)->not->toContain('deleteUsage')
        ->and($methods)->not->toContain('resetUsage');
})->group('BILL-USG-19');

it('BILL-USG-20: no delete method exists on service', function (): void {
    $methods = get_class_methods($this->service);

    expect($methods)->not->toContain('deleteUsage');
})->group('BILL-USG-20');
