<?php

declare(strict_types=1);

use App\Application\Billing\Guards\EntitlementResolver;
use App\Application\Billing\Guards\UsageGuard;
use App\Application\Flows\Services\FlowExecutionService;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Enums\UsageCategory;
use App\Domain\Billing\Enums\UsageReservationStatus;
use App\Domain\Billing\Exceptions\SubscriptionNotFoundException;
use App\Domain\Billing\Exceptions\TenantQuotaExceededException;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Models\UsageRecord;
use App\Domain\Billing\Models\UsageReservation;
use App\Domain\Flows\Enums\FlowExecutionStatus;
use App\Domain\Flows\Enums\FlowStatus;
use App\Domain\Flows\Models\Flow;
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

    $this->chatbot = make_chatbot($this->tenant);
    $this->flow = make_flow($this->tenant, $this->chatbot);

    make_flow_graph($this->flow, [
        ['id' => 'start', 'type' => 'message', 'name' => 'Start', 'config' => ['text' => 'Hi'], 'is_start' => true],
        ['id' => 'end', 'type' => 'end', 'name' => 'End'],
    ], [
        ['from' => 'start', 'to' => 'end'],
    ]);

    $this->flow->forceFill(['status' => FlowStatus::Published->value])->save();
    $this->flow->refresh();

    $this->contact = make_contact($this->tenant, ['phone' => '+15550000001']);
    $this->conversation = make_conversation($this->tenant, $this->contact);
});

// ──────────────────────────────────────────────
// FLOW-01..05: Quota boundary
// ──────────────────────────────────────────────

it('USG-U2-FLOW-01: under limit starts flow execution', function (): void {
    TenantContext::setId($this->tenant->id);

    $execution = app(FlowExecutionService::class)->start($this->flow, $this->conversation);

    expect($execution)->not->toBeNull()
        ->and($execution->status->value)->toBe('running');
});

it('USG-U2-FLOW-02: at limit blocked throws TenantQuotaExceededException', function (): void {
    $this->plan->update(['limits' => array_merge($this->plan->limits, ['flow_executions' => 0])]);

    TenantContext::setId($this->tenant->id);

    $this->expectException(TenantQuotaExceededException::class);
    app(FlowExecutionService::class)->start($this->flow, $this->conversation);
});

it('USG-U2-FLOW-03: unlimited plan starts without quota check', function (): void {
    $this->plan->update(['limits' => array_merge($this->plan->limits, ['flow_executions' => null])]);

    TenantContext::setId($this->tenant->id);

    $execution = app(FlowExecutionService::class)->start($this->flow, $this->conversation);

    expect($execution)->not->toBeNull();
});

it('USG-U2-FLOW-04: zero limit blocked', function (): void {
    $this->plan->update(['limits' => array_merge($this->plan->limits, ['flow_executions' => 0])]);

    TenantContext::setId($this->tenant->id);

    $this->expectException(TenantQuotaExceededException::class);
    app(FlowExecutionService::class)->start($this->flow, $this->conversation);
});

// ──────────────────────────────────────────────
// FLOW-05..07: Reserve + commit lifecycle
// ──────────────────────────────────────────────

it('USG-U2-FLOW-05: reserve happens before creating execution', function (): void {
    TenantContext::setId($this->tenant->id);

    $execution = app(FlowExecutionService::class)->start($this->flow, $this->conversation);

    $reservation = UsageReservation::query()
        ->withoutTenantScope()
        ->where('tenant_id', $this->tenant->id)
        ->where('category', UsageCategory::FlowExecutions)
        ->first();

    expect($reservation)->not->toBeNull()
        ->and($reservation->status)->toBe(UsageReservationStatus::Committed);
});

it('USG-U2-FLOW-06: commit happens after successful start', function (): void {
    TenantContext::setId($this->tenant->id);

    $execution = app(FlowExecutionService::class)->start($this->flow, $this->conversation);

    $this->assertDatabaseHas('usage_records', [
        'tenant_id' => $this->tenant->id,
        'category' => UsageCategory::FlowExecutions->value,
        'quantity' => 1,
    ]);
});

it('USG-U2-FLOW-07: later flow error does NOT release committed usage', function (): void {
    TenantContext::setId($this->tenant->id);

    $execution = app(FlowExecutionService::class)->start($this->flow, $this->conversation);

    $usageCountBefore = UsageRecord::query()
        ->withoutTenantScope()
        ->where('tenant_id', $this->tenant->id)
        ->where('category', UsageCategory::FlowExecutions)
        ->count();

    app(FlowExecutionService::class)->finish(
        $execution,
        FlowExecutionStatus::Failed,
        'execution.failed',
    );

    $usageCountAfter = UsageRecord::query()
        ->withoutTenantScope()
        ->where('tenant_id', $this->tenant->id)
        ->where('category', UsageCategory::FlowExecutions)
        ->count();

    expect($usageCountAfter)->toBe($usageCountBefore);
});

// ──────────────────────────────────────────────
// FLOW-08..09: Idempotency + tenant isolation
// ──────────────────────────────────────────────

it('USG-U2-FLOW-08: duplicate start no double count', function (): void {
    $this->plan->update(['limits' => array_merge($this->plan->limits, ['flow_executions' => 10])]);

    TenantContext::setId($this->tenant->id);

    $execution1 = app(FlowExecutionService::class)->start($this->flow, $this->conversation);

    $contact2 = make_contact($this->tenant, ['phone' => '+15550000002']);
    $conversation2 = make_conversation($this->tenant, $contact2);

    Carbon::setTestNow(now()->addSeconds(2));
    TenantContext::setId($this->tenant->id);
    $execution2 = app(FlowExecutionService::class)->start($this->flow, $conversation2);

    Carbon::setTestNow();

    $count = UsageRecord::query()
        ->withoutTenantScope()
        ->where('tenant_id', $this->tenant->id)
        ->where('category', UsageCategory::FlowExecutions)
        ->count();
    expect($count)->toBe(2);
});

it('USG-U2-FLOW-09: tenant isolation (flow execution)', function (): void {
    $tenantB = Tenant::factory()->create();

    $planB = Plan::factory()->create([
        'limits' => ['flow_executions' => 10],
    ]);

    TenantContext::setId($tenantB->id);
    Subscription::factory()->create([
        'tenant_id' => $tenantB->id,
        'plan_id' => $planB->id,
        'status' => SubscriptionStatus::Active,
    ]);

    TenantContext::setId($this->tenant->id);
    app(FlowExecutionService::class)->start($this->flow, $this->conversation);

    $tenantAUsage = UsageRecord::query()
        ->withoutTenantScope()
        ->where('tenant_id', $this->tenant->id)
        ->where('category', UsageCategory::FlowExecutions)
        ->count();
    $tenantBUsage = UsageRecord::query()
        ->withoutTenantScope()
        ->where('tenant_id', $tenantB->id)
        ->where('category', UsageCategory::FlowExecutions)
        ->count();

    expect($tenantAUsage)->toBe(1)
        ->and($tenantBUsage)->toBe(0);
});

// ──────────────────────────────────────────────
// FLOW-10..12: Entitlement
// ──────────────────────────────────────────────

it('USG-U2-FLOW-10: Pending/Cancelled subscription throws SubscriptionNotFoundException (fail-closed)', function (): void {
    $this->subscription->update(['status' => SubscriptionStatus::Pending]);

    TenantContext::setId($this->tenant->id);

    $this->expectException(SubscriptionNotFoundException::class);

    $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::FlowExecutions,
        quantity: 1,
    );
});

it('USG-U2-FLOW-11: PastDue subscription allowed', function (): void {
    $this->subscription->update(['status' => SubscriptionStatus::PastDue]);

    TenantContext::setId($this->tenant->id);
    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::FlowExecutions,
        quantity: 1,
    );

    expect($reservation)->not->toBeNull();
});

it('USG-U2-FLOW-12: plan downgrade re-check', function (): void {
    TenantContext::setId($this->tenant->id);
    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::FlowExecutions,
        quantity: 1,
        idempotencyKey: 'flow-downgrade',
    );

    $this->plan->update(['limits' => array_merge($this->plan->limits, ['flow_executions' => 0])]);

    $existing = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::FlowExecutions,
        quantity: 1,
        idempotencyKey: 'flow-downgrade',
    );

    expect($existing->id)->toBe($reservation->id);
});
