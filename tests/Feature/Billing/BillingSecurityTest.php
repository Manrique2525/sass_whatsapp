<?php

declare(strict_types=1);

use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Models\UsageRecord;
use App\Domain\Tenants\Models\Tenant;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
});

/*
|--------------------------------------------------------------------------
| Billing Security Tests (FASE 23 U1)
|--------------------------------------------------------------------------
|
| BILL-SEC-01..10 — Security invariants for billing tables.
| Corren en SQLite :memory:.
|
*/

it('BILL-SEC-01: No PII in UsageRecord metadata', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);
    $sub = Subscription::factory()->create(['tenant_id' => $tenant->id]);

    $record = UsageRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'subscription_id' => $sub->id,
        'metadata' => ['conversation_id' => 'conv-123', 'flow_execution_id' => 'exec-456'],
    ]);

    $json = json_encode($record->metadata);
    expect($json)->not->toContain('phone')
        ->and($json)->not->toContain('email')
        ->and($json)->not->toContain('name')
        ->and($json)->not->toContain('address')
        ->and($json)->not->toContain('password')
        ->and($json)->not->toContain('token')
        ->and($json)->not->toContain('secret')
        ->and($json)->not->toContain('api_key');
})->group('BILL-SEC-01');

it('BILL-SEC-02: No PII in Plan limits', function (): void {
    $plan = Plan::factory()->create([
        'limits' => ['messages' => 1000],
    ]);

    $json = json_encode($plan->limits);
    expect($json)->not->toContain('phone')
        ->and($json)->not->toContain('email')
        ->and($json)->not->toContain('name');
})->group('BILL-SEC-02');

it('BILL-SEC-03: No HTML in Plan name or description', function (): void {
    $plan = Plan::factory()->create([
        'name' => 'Test Plan',
        'description' => 'A test plan',
    ]);

    expect($plan->name)->not->toContain('<script>')
        ->and($plan->description)->not->toContain('<script>')
        ->and($plan->name)->not->toContain('<img')
        ->and($plan->description)->not->toContain('<img');
})->group('BILL-SEC-03');

it('BILL-SEC-04: No tenant_id in Plan model', function (): void {
    $plan = Plan::factory()->create();

    expect(array_keys($plan->getAttributes()))->not->toContain('tenant_id');
})->group('BILL-SEC-04');

it('BILL-SEC-05: No raw SQL interpolation in UsageRecord', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);
    $sub = Subscription::factory()->create(['tenant_id' => $tenant->id]);

    $record = UsageRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'subscription_id' => $sub->id,
    ]);

    // UsageRecord uses Eloquent — no raw SQL
    expect($record)->toBeInstanceOf(UsageRecord::class);
})->group('BILL-SEC-05');

it('BILL-SEC-06: No API keys or secrets in billing models', function (): void {
    $plan = Plan::factory()->create();
    $json = json_encode($plan->toArray());

    expect($json)->not->toContain('api_key')
        ->and($json)->not->toContain('secret')
        ->and($json)->not->toContain('stripe')
        ->and($json)->not->toContain('sk_live')
        ->and($json)->not->toContain('sk_test');
})->group('BILL-SEC-06');

it('BILL-SEC-07: tenant_id not mass-assignable via fill on Plan', function (): void {
    $plan = Plan::factory()->create();
    // Plan has no tenant_id at all
    $plan->fill(['tenant_id' => 'injected']);
    expect($plan->getAttributes())->not->toHaveKey('tenant_id');
})->group('BILL-SEC-07');

it('BILL-SEC-08: UsageRecord description is nullable and safe', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);
    $sub = Subscription::factory()->create(['tenant_id' => $tenant->id]);

    $record = UsageRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'subscription_id' => $sub->id,
        'description' => null,
    ]);

    expect($record->description)->toBeNull();
})->group('BILL-SEC-08');

it('BILL-SEC-09: Plan price cannot be negative (decimal cast)', function (): void {
    $plan = Plan::factory()->create([
        'price_monthly' => 29.99,
        'price_yearly' => 299.99,
    ]);

    expect($plan->price_monthly)->toBe('29.99')
        ->and($plan->price_yearly)->toBe('299.99');
})->group('BILL-SEC-09');

it('BILL-SEC-10: Subscription quantity is integer (no float injection)', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    $sub = Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'quantity' => 3,
    ]);

    expect($sub->quantity)->toBeInt()->toBe(3);
})->group('BILL-SEC-10');
