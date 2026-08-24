<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\UsageCategory;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Models\UsageRecord;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
});

/*
|--------------------------------------------------------------------------
| Usage API Tests (FASE 23 U3)
|--------------------------------------------------------------------------
|
| BILL-API-USG-01..08 — Usage summary + history endpoints.
| Corren en SQLite :memory:.
|
*/

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->owner = User::factory()->create();
    $this->plan = Plan::factory()->create();
    make_tenant_member($this->owner, $this->tenant, 'owner');
    TenantContext::setId($this->tenant->id);
    Subscription::factory()->create([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => 'active',
    ]);
});

it('BILL-API-USG-01: usage index returns summary for active subscription', function (): void {
    $response = $this->actingAs($this->owner)->getJson(
        '/api/v1/tenants/'.$this->tenant->id.'/usage',
    );

    $response->assertOk()->assertJsonStructure([
        'usage' => ['subscription_id', 'period_start', 'period_end', 'categories'],
    ]);
})->group('BILL-API-USG-01');

it('BILL-API-USG-02: usage index returns 404 when no subscription', function (): void {
    $otherTenant = Tenant::factory()->create();
    $otherPlan = Plan::factory()->create();
    $otherOwner = User::factory()->create();
    make_tenant_member($otherOwner, $otherTenant, 'owner');

    $response = $this->actingAs($otherOwner)->getJson(
        '/api/v1/tenants/'.$otherTenant->id.'/usage',
    );

    $response->assertNotFound()->assertJson([
        'code' => 'SUBSCRIPTION_NOT_FOUND',
    ]);
})->group('BILL-API-USG-02');

it('BILL-API-USG-03: usage history returns paginated records', function (): void {
    $sub = Subscription::withoutGlobalScopes()
        ->where('tenant_id', $this->tenant->id)
        ->first();

    for ($i = 0; $i < 3; $i++) {
        UsageRecord::factory()->create([
            'tenant_id' => $this->tenant->id,
            'subscription_id' => $sub->id,
            'category' => UsageCategory::Messages,
            'quantity' => 1,
            'recorded_at' => now()->subSeconds(10 - $i)->toDateTimeString(),
        ]);
    }

    $response = $this->actingAs($this->owner)->getJson(
        '/api/v1/tenants/'.$this->tenant->id.'/usage/history',
    );

    $response->assertOk()->assertJsonStructure([
        'usage_records' => [['id', 'category', 'quantity', 'recorded_at']],
        'meta' => ['current_page', 'last_page', 'per_page', 'total'],
    ]);

    $response->assertJsonPath('meta.total', 3);
})->group('BILL-API-USG-03');

it('BILL-API-USG-04: usage history filters by category', function (): void {
    $sub = Subscription::withoutGlobalScopes()
        ->where('tenant_id', $this->tenant->id)
        ->first();

    UsageRecord::factory()->create([
        'tenant_id' => $this->tenant->id,
        'subscription_id' => $sub->id,
        'category' => UsageCategory::Messages,
        'recorded_at' => now()->subSeconds(3)->toDateTimeString(),
    ]);
    UsageRecord::factory()->create([
        'tenant_id' => $this->tenant->id,
        'subscription_id' => $sub->id,
        'category' => UsageCategory::Messages,
        'recorded_at' => now()->subSeconds(2)->toDateTimeString(),
    ]);
    UsageRecord::factory()->create([
        'tenant_id' => $this->tenant->id,
        'subscription_id' => $sub->id,
        'category' => UsageCategory::AiTokens,
        'recorded_at' => now()->subSeconds(1)->toDateTimeString(),
    ]);

    $response = $this->actingAs($this->owner)->getJson(
        '/api/v1/tenants/'.$this->tenant->id.'/usage/history?category=messages',
    );

    $response->assertOk();
    $response->assertJsonPath('meta.total', 2);
})->group('BILL-API-USG-04');

it('BILL-API-USG-05: usage history filters by date range', function (): void {
    $sub = Subscription::withoutGlobalScopes()
        ->where('tenant_id', $this->tenant->id)
        ->first();

    UsageRecord::factory()->create([
        'tenant_id' => $this->tenant->id,
        'subscription_id' => $sub->id,
        'recorded_at' => Carbon::yesterday()->toDateTimeString(),
    ]);
    UsageRecord::factory()->create([
        'tenant_id' => $this->tenant->id,
        'subscription_id' => $sub->id,
        'recorded_at' => now()->toDateTimeString(),
    ]);

    $response = $this->actingAs($this->owner)->getJson(
        '/api/v1/tenants/'.$this->tenant->id.'/usage/history?from='.Carbon::today()->toDateString(),
    );

    $response->assertOk();
    $response->assertJsonPath('meta.total', 1);
})->group('BILL-API-USG-05');

it('BILL-API-USG-06: usage history per_page works', function (): void {
    $sub = Subscription::withoutGlobalScopes()
        ->where('tenant_id', $this->tenant->id)
        ->first();

    for ($i = 0; $i < 5; $i++) {
        UsageRecord::factory()->create([
            'tenant_id' => $this->tenant->id,
            'subscription_id' => $sub->id,
            'recorded_at' => now()->subSeconds(10 - $i)->toDateTimeString(),
        ]);
    }

    $response = $this->actingAs($this->owner)->getJson(
        '/api/v1/tenants/'.$this->tenant->id.'/usage/history?per_page=2',
    );

    $response->assertOk();
    $response->assertJsonPath('meta.total', 5);
    $response->assertJsonCount(2, 'usage_records');
})->group('BILL-API-USG-06');

it('BILL-API-USG-07: unauthenticated access returns 401', function (): void {
    $response = $this->getJson(
        '/api/v1/tenants/'.$this->tenant->id.'/usage',
    );

    $response->assertUnauthorized();
})->group('BILL-API-USG-07');

it('BILL-API-USG-08: agent cannot access usage (403)', function (): void {
    $agent = User::factory()->create();
    make_tenant_member($agent, $this->tenant, 'agent');

    $response = $this->actingAs($agent)->getJson(
        '/api/v1/tenants/'.$this->tenant->id.'/usage',
    );

    $response->assertForbidden()->assertJson([
        'code' => 'PERMISSION_DENIED',
    ]);
})->group('BILL-API-USG-08');
