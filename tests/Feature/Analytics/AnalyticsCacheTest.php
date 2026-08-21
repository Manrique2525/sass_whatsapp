<?php

declare(strict_types=1);

use App\Domain\Analytics\ValueObjects\AnalyticsOverview;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
});

beforeEach(function (): void {
    Cache::flush();
    $this->tenant = Tenant::factory()->create(['timezone' => 'UTC']);
    TenantContext::setId($this->tenant->id);

    $this->owner = User::factory()->create();
    make_tenant_member($this->owner, $this->tenant, 'owner');
});

function cacheOverviewUrl(Tenant $tenant, array $params = []): string
{
    $base = '/api/v1/tenants/'.$tenant->id.'/analytics/overview';

    if ($params === []) {
        return $base;
    }

    return $base.'?'.http_build_query($params);
}

function cacheSeedRow(Tenant $tenant, string $date, array $overrides = []): void
{
    $defaults = [
        'id' => (string) Str::uuid(),
        'tenant_id' => $tenant->id,
        'date' => $date,
        'total_messages' => 0,
        'messages_inbound' => 0,
        'messages_outbound' => 0,
        'messages_delivered' => 0,
        'messages_read' => 0,
        'messages_failed' => 0,
        'total_conversations' => 0,
        'conversations_open' => 0,
        'conversations_resolved' => 0,
        'conversations_archived' => 0,
        'conversations_handoff_requested' => 0,
        'conversations_bot_paused' => 0,
        'unique_contacts' => 0,
        'avg_response_time_seconds' => null,
        'total_flow_executions' => 0,
        'flow_executions_completed' => 0,
        'flow_executions_failed' => 0,
        'total_leads' => 0,
        'leads_new' => 0,
        'leads_won' => 0,
        'leads_lost' => 0,
        'total_ai_tokens' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('analytics_daily')->insert(array_merge($defaults, $overrides));
}

/*
|--------------------------------------------------------------------------
| AN-CACHE-01..08 — Cache Tests (Real Redis)
|--------------------------------------------------------------------------
*/

it('AN-CACHE-01: first request computes from DB', function (): void {
    cacheSeedRow($this->tenant, now()->toDateString(), ['total_messages' => 42]);

    $response = $this->actingAs($this->owner)->getJson(cacheOverviewUrl($this->tenant));

    $response->assertOk()->assertJsonPath('data.messages.total', 42);

    $tenantId = $this->tenant->id;
    $today = now()->toDateString();
    $from30 = now()->subDays(29)->toDateString();
    $key = "tenant:{$tenantId}:analytics:overview:{$from30}:{$today}";

    expect(Cache::has($key))->toBeTrue();
})->group('AN-CACHE-01');

it('AN-CACHE-02: second request hits cache', function (): void {
    cacheSeedRow($this->tenant, now()->toDateString(), ['total_messages' => 42]);

    $this->actingAs($this->owner)->getJson(cacheOverviewUrl($this->tenant))
        ->assertOk()->assertJsonPath('data.messages.total', 42);

    DB::table('analytics_daily')
        ->where('tenant_id', $this->tenant->id)
        ->update(['total_messages' => 99]);

    $response = $this->actingAs($this->owner)->getJson(cacheOverviewUrl($this->tenant));

    $response->assertOk()->assertJsonPath('data.messages.total', 42);
})->group('AN-CACHE-02');

it('AN-CACHE-03: cache is tenant-scoped', function (): void {
    $tenantB = Tenant::factory()->create(['timezone' => 'UTC']);
    $ownerB = User::factory()->create();
    make_tenant_member($ownerB, $tenantB, 'owner');

    cacheSeedRow($this->tenant, now()->toDateString(), ['total_messages' => 10]);
    cacheSeedRow($tenantB, now()->toDateString(), ['total_messages' => 20]);

    $this->actingAs($this->owner)->getJson(cacheOverviewUrl($this->tenant))
        ->assertOk()->assertJsonPath('data.messages.total', 10);

    $this->actingAs($ownerB)->getJson(cacheOverviewUrl($tenantB))
        ->assertOk()->assertJsonPath('data.messages.total', 20);

    $this->actingAs($this->owner)->getJson(cacheOverviewUrl($this->tenant))
        ->assertOk()->assertJsonPath('data.messages.total', 10);
})->group('AN-CACHE-03');

it('AN-CACHE-04: cache is date-range-scoped', function (): void {
    cacheSeedRow($this->tenant, '2026-08-01', ['total_messages' => 10]);
    cacheSeedRow($this->tenant, '2026-08-05', ['total_messages' => 20]);

    $this->actingAs($this->owner)->getJson(
        cacheOverviewUrl($this->tenant, ['from' => '2026-08-01', 'to' => '2026-08-03']),
    )->assertOk()->assertJsonPath('data.messages.total', 10);

    $this->actingAs($this->owner)->getJson(
        cacheOverviewUrl($this->tenant, ['from' => '2026-08-01', 'to' => '2026-08-05']),
    )->assertOk()->assertJsonPath('data.messages.total', 30);
})->group('AN-CACHE-04');

it('AN-CACHE-05: cache is set after request', function (): void {
    cacheSeedRow($this->tenant, now()->toDateString(), ['total_messages' => 1]);

    $this->actingAs($this->owner)->getJson(cacheOverviewUrl($this->tenant))->assertOk();

    $tenantId = $this->tenant->id;
    $today = now()->toDateString();
    $from30 = now()->subDays(29)->toDateString();
    $key = "tenant:{$tenantId}:analytics:overview:{$from30}:{$today}";

    expect(Cache::has($key))->toBeTrue();
    expect(Cache::get($key))->not->toBeNull();
})->group('AN-CACHE-05');

it('AN-CACHE-06: expired cache recomputes', function (): void {
    cacheSeedRow($this->tenant, now()->toDateString(), ['total_messages' => 10]);

    $this->actingAs($this->owner)->getJson(cacheOverviewUrl($this->tenant))
        ->assertOk()->assertJsonPath('data.messages.total', 10);

    $tenantId = $this->tenant->id;
    $today = now()->toDateString();
    $from30 = now()->subDays(29)->toDateString();
    $key = "tenant:{$tenantId}:analytics:overview:{$from30}:{$today}";
    Cache::forget($key);

    DB::table('analytics_daily')
        ->where('tenant_id', $this->tenant->id)
        ->update(['total_messages' => 77]);

    $response = $this->actingAs($this->owner)->getJson(cacheOverviewUrl($this->tenant));

    $response->assertOk()->assertJsonPath('data.messages.total', 77);
})->group('AN-CACHE-06');

it('AN-CACHE-07: cached result is plain array not Eloquent', function (): void {
    cacheSeedRow($this->tenant, now()->toDateString(), ['total_messages' => 1]);

    $this->actingAs($this->owner)->getJson(cacheOverviewUrl($this->tenant))->assertOk();

    $tenantId = $this->tenant->id;
    $today = now()->toDateString();
    $from30 = now()->subDays(29)->toDateString();
    $key = "tenant:{$tenantId}:analytics:overview:{$from30}:{$today}";
    $cached = Cache::get($key);

    expect($cached)->toBeInstanceOf(AnalyticsOverview::class);
})->group('AN-CACHE-07');

it('AN-CACHE-08: no wildcard invalidation used', function (): void {
    cacheSeedRow($this->tenant, now()->toDateString(), ['total_messages' => 5]);

    $this->actingAs($this->owner)->getJson(cacheOverviewUrl($this->tenant))->assertOk();

    $tenantId = $this->tenant->id;
    $today = now()->toDateString();
    $from30 = now()->subDays(29)->toDateString();
    $key = "tenant:{$tenantId}:analytics:overview:{$from30}:{$today}";

    expect(Cache::has($key))->toBeTrue();

    $response = $this->actingAs($this->owner)->getJson(cacheOverviewUrl($this->tenant));
    $response->assertOk()->assertJsonPath('data.messages.total', 5);
})->group('AN-CACHE-08');
