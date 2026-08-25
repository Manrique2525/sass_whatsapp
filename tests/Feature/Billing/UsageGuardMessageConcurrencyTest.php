<?php

declare(strict_types=1);

use App\Application\Billing\Guards\EntitlementResolver;
use App\Application\Billing\Guards\UsageGuard;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Enums\UsageCategory;
use App\Domain\Billing\Enums\UsageReservationStatus;
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
    $this->guard = new UsageGuard(new EntitlementResolver);

    $this->tenant = Tenant::factory()->create();
    TenantContext::setId($this->tenant->id);

    $this->plan = Plan::factory()->create([
        'limits' => [
            'messages' => 5,
            'ai_tokens' => 5000,
            'contacts' => 50,
            'flow_executions' => 5,
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

it('USG-U2-MSG-CONC-01: message boundary — reserve consumes, commit persists, release restores', function (): void {
    TenantContext::setId($this->tenant->id);

    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 3,
    );

    $remainingAfterReserve = $this->guard->remaining($this->tenant, UsageCategory::Messages);
    expect($remainingAfterReserve)->toBe(2);

    $this->guard->commit($reservation);

    $remainingAfterCommit = $this->guard->remaining($this->tenant, UsageCategory::Messages);
    expect($remainingAfterCommit)->toBe(2);

    $reservation2 = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 2,
        idempotencyKey: 'release-test',
    );

    $this->guard->release($reservation2);

    $remainingAfterRelease = $this->guard->remaining($this->tenant, UsageCategory::Messages);
    expect($remainingAfterRelease)->toBe(2);
});

it('USG-U2-MSG-CONC-02: same-message idempotent retry returns same reservation', function (): void {
    TenantContext::setId($this->tenant->id);

    $key = 'msg-idempotent-conc-'.uniqid();

    $r1 = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 1,
        idempotencyKey: $key,
    );

    $r2 = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 1,
        idempotencyKey: $key,
    );

    expect($r1->id)->toBe($r2->id);

    $this->guard->commit($r1);

    $r3 = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 1,
        idempotencyKey: $key,
    );

    expect($r3->id)->toBe($r1->id)
        ->and($r3->status)->toBe(UsageReservationStatus::Committed);
});

it('USG-U2-MSG-CONC-03: cross-tenant message quotas are independent', function (): void {
    $tenantB = Tenant::factory()->create();
    $planB = Plan::factory()->create(['limits' => ['messages' => 3]]);

    TenantContext::setId($tenantB->id);
    Subscription::factory()->create([
        'tenant_id' => $tenantB->id,
        'plan_id' => $planB->id,
        'status' => SubscriptionStatus::Active,
        'current_period_start' => Carbon::parse('2026-08-01'),
        'current_period_end' => Carbon::parse('2026-09-01'),
    ]);

    TenantContext::setId($this->tenant->id);
    for ($i = 0; $i < 5; $i++) {
        $this->guard->reserve(
            tenant: $this->tenant,
            category: UsageCategory::Messages,
            quantity: 1,
            idempotencyKey: "tenantA-conc-{$i}",
        );
    }

    $remainingA = $this->guard->remaining($this->tenant, UsageCategory::Messages);
    expect($remainingA)->toBe(0);

    TenantContext::setId($tenantB->id);
    $rB = $this->guard->reserve(
        tenant: $tenantB,
        category: UsageCategory::Messages,
        quantity: 1,
    );
    expect($rB)->not->toBeNull();
});

it('USG-U2-MSG-CONC-04: message and flow categories are independent', function (): void {
    TenantContext::setId($this->tenant->id);

    for ($i = 0; $i < 5; $i++) {
        $this->guard->reserve(
            tenant: $this->tenant,
            category: UsageCategory::Messages,
            quantity: 1,
            idempotencyKey: "cat-indep-conc-{$i}",
        );
    }

    $remainingMsg = $this->guard->remaining($this->tenant, UsageCategory::Messages);
    expect($remainingMsg)->toBe(0);

    $remainingFlow = $this->guard->remaining($this->tenant, UsageCategory::FlowExecutions);
    expect($remainingFlow)->toBe(5);

    $rFlow = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::FlowExecutions,
        quantity: 1,
    );
    expect($rFlow)->not->toBeNull();
});

it('USG-U2-MSG-CONC-05: missing subscription throws SubscriptionNotFoundException (fail-closed)', function (): void {
    $tenantNoSub = Tenant::factory()->create();

    TenantContext::setId($tenantNoSub->id);

    $this->expectException(SubscriptionNotFoundException::class);

    $this->guard->reserve(
        tenant: $tenantNoSub,
        category: UsageCategory::Messages,
        quantity: 1,
    );
});
