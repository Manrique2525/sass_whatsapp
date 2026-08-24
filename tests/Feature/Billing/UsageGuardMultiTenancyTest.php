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

    $this->tenantA = Tenant::factory()->create();
    $this->tenantB = Tenant::factory()->create();

    $this->planA = Plan::factory()->create([
        'limits' => [
            'messages' => 100,
            'ai_tokens' => 5000,
            'contacts' => 50,
            'flow_executions' => 10,
            'users' => 3,
            'knowledge_documents' => 5,
        ],
    ]);

    $this->planB = Plan::factory()->create([
        'limits' => [
            'messages' => 200,
            'ai_tokens' => 10000,
            'contacts' => 100,
            'flow_executions' => 20,
            'users' => 5,
            'knowledge_documents' => 10,
        ],
    ]);

    TenantContext::setId($this->tenantA->id);
    $this->subscriptionA = Subscription::factory()->create([
        'tenant_id' => $this->tenantA->id,
        'plan_id' => $this->planA->id,
        'status' => SubscriptionStatus::Active,
        'current_period_start' => Carbon::parse('2026-08-01'),
        'current_period_end' => Carbon::parse('2026-09-01'),
    ]);

    TenantContext::setId($this->tenantB->id);
    $this->subscriptionB = Subscription::factory()->create([
        'tenant_id' => $this->tenantB->id,
        'plan_id' => $this->planB->id,
        'status' => SubscriptionStatus::Active,
        'current_period_start' => Carbon::parse('2026-08-01'),
        'current_period_end' => Carbon::parse('2026-09-01'),
    ]);

    TenantContext::clear();
});

it('USG-U1-MT-01: tenant A usage does not affect tenant B remaining', function (): void {
    TenantContext::setId($this->tenantA->id);
    $this->guard->reserve(
        tenant: $this->tenantA,
        category: UsageCategory::Messages,
        quantity: 50,
    );

    TenantContext::setId($this->tenantB->id);
    $remaining = $this->guard->remaining($this->tenantB, UsageCategory::Messages);

    expect($remaining)->toBe(200);
})->group('USG-U1-MT-01');

it('USG-U1-MT-02: same idempotency key is independent per tenant', function (): void {
    TenantContext::setId($this->tenantA->id);
    $rA = $this->guard->reserve(
        tenant: $this->tenantA,
        category: UsageCategory::Messages,
        quantity: 10,
        idempotencyKey: 'shared-key',
    );

    TenantContext::setId($this->tenantB->id);
    $rB = $this->guard->reserve(
        tenant: $this->tenantB,
        category: UsageCategory::Messages,
        quantity: 20,
        idempotencyKey: 'shared-key',
    );

    expect($rA->id)->not->toBe($rB->id)
        ->and($rA->quantity)->toBe(10)
        ->and($rB->quantity)->toBe(20);
})->group('USG-U1-MT-02');

it('USG-U1-MT-03: tenant A commit does not affect tenant B', function (): void {
    TenantContext::setId($this->tenantA->id);
    $rA = $this->guard->reserve(
        tenant: $this->tenantA,
        category: UsageCategory::Messages,
        quantity: 50,
    );
    $this->guard->commit($rA);

    TenantContext::setId($this->tenantB->id);
    $remaining = $this->guard->remaining($this->tenantB, UsageCategory::Messages);

    expect($remaining)->toBe(200);
})->group('USG-U1-MT-03');

it('USG-U1-MT-04: tenant A release does not affect tenant B', function (): void {
    TenantContext::setId($this->tenantA->id);
    $rA = $this->guard->reserve(
        tenant: $this->tenantA,
        category: UsageCategory::Messages,
        quantity: 50,
    );
    $this->guard->release($rA);

    TenantContext::setId($this->tenantB->id);
    $remaining = $this->guard->remaining($this->tenantB, UsageCategory::Messages);

    expect($remaining)->toBe(200);
})->group('USG-U1-MT-04');

it('USG-U1-MT-05: concurrent reserves from different tenants do not interfere', function (): void {
    TenantContext::setId($this->tenantA->id);
    $rA = $this->guard->reserve(
        tenant: $this->tenantA,
        category: UsageCategory::Messages,
        quantity: 90,
    );

    TenantContext::setId($this->tenantB->id);
    $rB = $this->guard->reserve(
        tenant: $this->tenantB,
        category: UsageCategory::Messages,
        quantity: 150,
    );

    expect($rA->status)->toBe(UsageReservationStatus::Reserved)
        ->and($rB->status)->toBe(UsageReservationStatus::Reserved);
})->group('USG-U1-MT-05');

it('USG-U1-MT-06: tenant cannot reserve on another tenant subscription', function (): void {
    TenantContext::setId($this->tenantA->id);

    $reservation = $this->guard->reserve(
        tenant: $this->tenantA,
        category: UsageCategory::Messages,
        quantity: 5,
    );

    expect($reservation->tenant_id)->toBe($this->tenantA->id)
        ->and($reservation->subscription_id)->toBe($this->subscriptionA->id);
})->group('USG-U1-MT-06');
