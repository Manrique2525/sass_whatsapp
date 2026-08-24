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

it('USG-U1-SEC-01: tenant_id is never from caller input', function (): void {
    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 5,
    );

    expect($reservation->tenant_id)->toBe($this->tenant->id);
})->group('USG-U1-SEC-01');

it('USG-U1-SEC-02: category is always enum type', function (): void {
    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 5,
    );

    expect($reservation->category)->toBeInstanceOf(UsageCategory::class);
})->group('USG-U1-SEC-02');

it('USG-U1-SEC-03: exception exposes only safe fields', function (): void {
    UsageRecord::create([
        'tenant_id' => $this->tenant->id,
        'subscription_id' => $this->subscription->id,
        'category' => UsageCategory::Messages,
        'quantity' => 100,
        'metadata' => [],
        'recorded_at' => now(),
    ]);

    try {
        $this->guard->reserve(
            tenant: $this->tenant,
            category: UsageCategory::Messages,
            quantity: 1,
        );
        $this->fail('Expected TenantQuotaExceededException');
    } catch (TenantQuotaExceededException $e) {
        expect($e->category)->toBe('messages')
            ->and($e->limit)->toBe(100)
            ->and($e->used)->toBe(100);

        $json = json_encode($e);
        expect($json)->not->toContain($this->tenant->id)
            ->and($json)->not->toContain($this->subscription->id)
            ->and($json)->not->toContain($this->plan->id);
    }
})->group('USG-U1-SEC-03');

it('USG-U1-SEC-04: negative quantity rejected at application level', function (): void {
    $this->expectException(\App\Domain\Billing\Exceptions\InvalidUsageQuantityException::class);

    $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: -1,
    );
})->group('USG-U1-SEC-04');

it('USG-U1-SEC-05: zero quantity rejected at application level', function (): void {
    $this->expectException(\App\Domain\Billing\Exceptions\InvalidUsageQuantityException::class);

    $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 0,
    );
})->group('USG-U1-SEC-05');

it('USG-U1-SEC-06: no PII in exception messages', function (): void {
    UsageRecord::create([
        'tenant_id' => $this->tenant->id,
        'subscription_id' => $this->subscription->id,
        'category' => UsageCategory::Messages,
        'quantity' => 100,
        'metadata' => [],
        'recorded_at' => now(),
    ]);

    try {
        $this->guard->reserve(
            tenant: $this->tenant,
            category: UsageCategory::Messages,
            quantity: 1,
        );
        $this->fail('Expected TenantQuotaExceededException');
    } catch (TenantQuotaExceededException $e) {
        $message = $e->getMessage();
        expect($message)->not->toContain('phone')
            ->and($message)->not->toContain('email')
            ->and($message)->not->toContain('password')
            ->and($message)->not->toContain('api_key')
            ->and($message)->not->toContain('secret');
    }
})->group('USG-U1-SEC-06');

it('USG-U1-SEC-07: no API keys or secrets in guard source', function (): void {
    $source = file_get_contents(base_path('app/Application/Billing/Guards/UsageGuard.php'));

    expect($source)->not->toContain('sk_live')
        ->and($source)->not->toContain('sk_test')
        ->and($source)->not->toContain('api_key')
        ->and($source)->not->toContain('secret');
})->group('USG-U1-SEC-07');

it('USG-U1-SEC-08: exception code is 429', function (): void {
    UsageRecord::create([
        'tenant_id' => $this->tenant->id,
        'subscription_id' => $this->subscription->id,
        'category' => UsageCategory::Messages,
        'quantity' => 100,
        'metadata' => [],
        'recorded_at' => now(),
    ]);

    try {
        $this->guard->reserve(
            tenant: $this->tenant,
            category: UsageCategory::Messages,
            quantity: 1,
        );
        $this->fail('Expected TenantQuotaExceededException');
    } catch (TenantQuotaExceededException $e) {
        expect($e->getCode())->toBe(429);
    }
})->group('USG-U1-SEC-08');

it('USG-U1-SEC-09: reservation does not expose subscription internals', function (): void {
    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 5,
    );

    $json = json_encode($reservation->toArray());
    expect($json)->not->toContain('stripe')
        ->and($json)->not->toContain('plan_id')
        ->and($json)->not->toContain('price');
})->group('USG-U1-SEC-09');

it('USG-U1-SEC-10: guard source has no hardcoded secrets', function (): void {
    $source = file_get_contents(base_path('app/Application/Billing/Guards/UsageGuard.php'));

    expect($source)->not->toContain('sk_live')
        ->and($source)->not->toContain('sk_test')
        ->and($source)->not->toContain('whsec_');
})->group('USG-U1-SEC-10');
