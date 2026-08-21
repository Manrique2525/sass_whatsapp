<?php

declare(strict_types=1);

use App\Application\Analytics\Services\AggregationService;
use App\Domain\Contacts\Models\Contact;
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
    $this->admin = User::factory()->create();
    $this->agent = User::factory()->create();

    make_tenant_member($this->owner, $this->tenant, 'owner');
    make_tenant_member($this->admin, $this->tenant, 'admin');
    make_tenant_member($this->agent, $this->tenant, 'agent');

    $this->service = app(AggregationService::class);
});

function analyticsOverviewUrl(Tenant $tenant, array $params = []): string
{
    $base = '/api/v1/tenants/'.$tenant->id.'/analytics/overview';

    if ($params === []) {
        return $base;
    }

    return $base.'?'.http_build_query($params);
}

function seedAnalyticsRow(Tenant $tenant, string $date, array $overrides = []): void
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
| AN-API-01..13 — API Behavior Tests
|--------------------------------------------------------------------------
*/

it('AN-API-01: default range returns last 30 days', function (): void {
    $today = now()->toDateString();
    $from30 = now()->subDays(29)->toDateString();

    seedAnalyticsRow($this->tenant, $today, ['total_messages' => 5]);
    seedAnalyticsRow($this->tenant, $from30, ['total_messages' => 3]);

    $response = $this->actingAs($this->owner)->getJson(analyticsOverviewUrl($this->tenant));

    $response->assertOk()
        ->assertJsonPath('data.period.from', $from30)
        ->assertJsonPath('data.period.to', $today);
})->group('AN-API-01');

it('AN-API-02: explicit range works', function (): void {
    seedAnalyticsRow($this->tenant, '2026-08-01', ['total_messages' => 10]);
    seedAnalyticsRow($this->tenant, '2026-08-03', ['total_messages' => 20]);

    $response = $this->actingAs($this->owner)->getJson(
        analyticsOverviewUrl($this->tenant, ['from' => '2026-08-01', 'to' => '2026-08-03']),
    );

    $response->assertOk()
        ->assertJsonPath('data.period.from', '2026-08-01')
        ->assertJsonPath('data.period.to', '2026-08-03')
        ->assertJsonPath('data.messages.total', 30);
})->group('AN-API-02');

it('AN-API-03: max 365 days accepted', function (): void {
    $from = now()->subDays(364)->toDateString();
    $to = now()->toDateString();

    $response = $this->actingAs($this->owner)->getJson(
        analyticsOverviewUrl($this->tenant, ['from' => $from, 'to' => $to]),
    );

    $response->assertOk()
        ->assertJsonPath('data.period.from', $from)
        ->assertJsonPath('data.period.to', $to);
})->group('AN-API-03');

it('AN-API-04: 366 days rejected', function (): void {
    $from = now()->subDays(365)->toDateString();
    $to = now()->toDateString();

    $response = $this->actingAs($this->owner)->getJson(
        analyticsOverviewUrl($this->tenant, ['from' => $from, 'to' => $to]),
    );

    $response->assertStatus(422);
})->group('AN-API-04');

it('AN-API-05: from > to rejected', function (): void {
    $response = $this->actingAs($this->owner)->getJson(
        analyticsOverviewUrl($this->tenant, ['from' => '2026-08-10', 'to' => '2026-08-01']),
    );

    $response->assertStatus(422);
})->group('AN-API-05');

it('AN-API-06: invalid from date rejected', function (): void {
    $response = $this->actingAs($this->owner)->getJson(
        analyticsOverviewUrl($this->tenant, ['from' => 'not-a-date']),
    );

    $response->assertStatus(422);
})->group('AN-API-06');

it('AN-API-07: invalid to date rejected', function (): void {
    $response = $this->actingAs($this->owner)->getJson(
        analyticsOverviewUrl($this->tenant, ['to' => '2026-13-45']),
    );

    $response->assertStatus(422);
})->group('AN-API-07');

it('AN-API-08: empty data returns 200 with zeros', function (): void {
    $response = $this->actingAs($this->owner)->getJson(analyticsOverviewUrl($this->tenant));

    $response->assertOk()
        ->assertJsonPath('data.messages.total', 0)
        ->assertJsonPath('data.conversations.total', 0)
        ->assertJsonPath('data.conversations.avg_response_time_seconds', null)
        ->assertJsonPath('data.daily', []);
})->group('AN-API-08');

it('AN-API-09: response shape matches contract', function (): void {
    seedAnalyticsRow($this->tenant, now()->toDateString(), [
        'total_messages' => 5,
        'messages_inbound' => 3,
        'messages_outbound' => 2,
        'messages_delivered' => 4,
        'messages_read' => 1,
        'messages_failed' => 0,
        'total_conversations' => 2,
        'conversations_open' => 1,
        'conversations_resolved' => 1,
        'total_flow_executions' => 3,
        'flow_executions_completed' => 2,
        'flow_executions_failed' => 1,
        'total_leads' => 4,
        'leads_new' => 2,
        'leads_won' => 1,
        'leads_lost' => 1,
        'total_ai_tokens' => 100,
    ]);

    $response = $this->actingAs($this->owner)->getJson(analyticsOverviewUrl($this->tenant));

    $response->assertOk();

    $data = $response->json('data');

    expect($data)->toHaveKeys([
        'period', 'messages', 'conversations', 'flows', 'leads', 'ai', 'daily',
    ]);

    expect($data['period'])->toHaveKeys(['from', 'to']);
    expect($data['messages'])->toHaveKeys(['total', 'inbound', 'outbound', 'delivered', 'read', 'failed']);
    expect($data['conversations'])->toHaveKeys([
        'total', 'open', 'resolved', 'archived', 'handoff_requested', 'bot_paused',
        'unique_contacts', 'avg_response_time_seconds',
    ]);
    expect($data['flows'])->toHaveKeys(['total', 'completed', 'failed']);
    expect($data['leads'])->toHaveKeys(['total', 'new', 'won', 'lost']);
    expect($data['ai'])->toHaveKeys(['total_tokens']);
    expect($data['daily'])->toBeArray();
})->group('AN-API-09');

it('AN-API-10: sums across multiple days', function (): void {
    seedAnalyticsRow($this->tenant, '2026-08-01', ['total_messages' => 10, 'total_leads' => 3]);
    seedAnalyticsRow($this->tenant, '2026-08-02', ['total_messages' => 20, 'total_leads' => 7]);

    $response = $this->actingAs($this->owner)->getJson(
        analyticsOverviewUrl($this->tenant, ['from' => '2026-08-01', 'to' => '2026-08-02']),
    );

    $response->assertOk()
        ->assertJsonPath('data.messages.total', 30)
        ->assertJsonPath('data.leads.total', 10);
})->group('AN-API-10');

it('AN-API-11: average response time from conversation_metrics', function (): void {
    $contact = Contact::create([
        'name' => 'Test Contact',
        'phone' => '+1555000000',
    ]);

    $convId1 = (string) Str::uuid();
    $convId2 = (string) Str::uuid();

    DB::table('conversations')->insert([
        ['id' => $convId1, 'tenant_id' => $this->tenant->id, 'contact_id' => $contact->id, 'status' => 'open', 'bot_paused' => false, 'auto_assigned' => false, 'created_at' => now(), 'updated_at' => now()],
        ['id' => $convId2, 'tenant_id' => $this->tenant->id, 'contact_id' => $contact->id, 'status' => 'open', 'bot_paused' => false, 'auto_assigned' => false, 'created_at' => now(), 'updated_at' => now()],
    ]);

    DB::table('conversation_metrics')->insert([
        [
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'conversation_id' => $convId1,
            'response_time_seconds' => 10,
            'first_response_at' => '2026-08-15 10:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'conversation_id' => $convId2,
            'response_time_seconds' => 20,
            'first_response_at' => '2026-08-16 11:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $response = $this->actingAs($this->owner)->getJson(
        analyticsOverviewUrl($this->tenant, ['from' => '2026-08-15', 'to' => '2026-08-16']),
    );

    $response->assertOk()
        ->assertJsonPath('data.conversations.avg_response_time_seconds', 15);
})->group('AN-API-11');

it('AN-API-12: daily series includes all days in range', function (): void {
    seedAnalyticsRow($this->tenant, '2026-08-01', ['total_messages' => 5]);
    seedAnalyticsRow($this->tenant, '2026-08-03', ['total_messages' => 10]);

    $response = $this->actingAs($this->owner)->getJson(
        analyticsOverviewUrl($this->tenant, ['from' => '2026-08-01', 'to' => '2026-08-03']),
    );

    $response->assertOk();

    $daily = $response->json('data.daily');
    expect($daily)->toHaveCount(3);
    expect($daily[0]['date'])->toBe('2026-08-01');
    expect($daily[1]['date'])->toBe('2026-08-02');
    expect($daily[2]['date'])->toBe('2026-08-03');
})->group('AN-API-12');

it('AN-API-13: missing dates filled with zeros', function (): void {
    seedAnalyticsRow($this->tenant, '2026-08-01', ['total_messages' => 5]);

    $response = $this->actingAs($this->owner)->getJson(
        analyticsOverviewUrl($this->tenant, ['from' => '2026-08-01', 'to' => '2026-08-03']),
    );

    $response->assertOk();

    $daily = $response->json('data.daily');
    expect($daily[0]['messages_total'])->toBe(5);
    expect($daily[1]['messages_total'])->toBe(0);
    expect($daily[2]['messages_total'])->toBe(0);
})->group('AN-API-13');

/*
|--------------------------------------------------------------------------
| AN-PERM-01..04 — Permission Tests
|--------------------------------------------------------------------------
*/

it('AN-PERM-01: owner can access', function (): void {
    $this->actingAs($this->owner)->getJson(analyticsOverviewUrl($this->tenant))
        ->assertOk();
})->group('AN-PERM-01');

it('AN-PERM-02: admin can access', function (): void {
    $this->actingAs($this->admin)->getJson(analyticsOverviewUrl($this->tenant))
        ->assertOk();
})->group('AN-PERM-02');

it('AN-PERM-03: agent denied 403', function (): void {
    $this->actingAs($this->agent)->getJson(analyticsOverviewUrl($this->tenant))
        ->assertStatus(403)
        ->assertJsonPath('code', 'PERMISSION_DENIED');
})->group('AN-PERM-03');

it('AN-PERM-04: unauthenticated 401', function (): void {
    $this->getJson(analyticsOverviewUrl($this->tenant))
        ->assertStatus(401);
})->group('AN-PERM-04');

/*
|--------------------------------------------------------------------------
| AN-MT-U3-01..08 — Multi-tenancy Tests
|--------------------------------------------------------------------------
*/

it('AN-MT-U3-01: tenant A overview excludes tenant B data', function (): void {
    $tenantB = Tenant::factory()->create(['timezone' => 'UTC']);
    $ownerB = User::factory()->create();
    make_tenant_member($ownerB, $tenantB, 'owner');

    seedAnalyticsRow($this->tenant, now()->toDateString(), ['total_messages' => 50]);
    seedAnalyticsRow($tenantB, now()->toDateString(), ['total_messages' => 100]);

    $this->actingAs($this->owner)->getJson(analyticsOverviewUrl($this->tenant))
        ->assertOk()->assertJsonPath('data.messages.total', 50);

    $this->actingAs($ownerB)->getJson(analyticsOverviewUrl($tenantB))
        ->assertOk()->assertJsonPath('data.messages.total', 100);
})->group('AN-MT-U3-01');

it('AN-MT-U3-02: cache A differs from cache B', function (): void {
    $tenantB = Tenant::factory()->create(['timezone' => 'UTC']);
    $ownerB = User::factory()->create();
    make_tenant_member($ownerB, $tenantB, 'owner');

    seedAnalyticsRow($this->tenant, now()->toDateString(), ['total_messages' => 10]);
    seedAnalyticsRow($tenantB, now()->toDateString(), ['total_messages' => 20]);

    $this->actingAs($this->owner)->getJson(analyticsOverviewUrl($this->tenant))
        ->assertOk()->assertJsonPath('data.messages.total', 10);

    $this->actingAs($ownerB)->getJson(analyticsOverviewUrl($tenantB))
        ->assertOk()->assertJsonPath('data.messages.total', 20);
})->group('AN-MT-U3-02');

it('AN-MT-U3-03: same dates A and B are independent', function (): void {
    $tenantB = Tenant::factory()->create(['timezone' => 'UTC']);
    $ownerB = User::factory()->create();
    make_tenant_member($ownerB, $tenantB, 'owner');

    seedAnalyticsRow($this->tenant, '2026-08-10', ['total_flow_executions' => 5]);
    seedAnalyticsRow($tenantB, '2026-08-10', ['total_flow_executions' => 15]);

    $this->actingAs($this->owner)->getJson(
        analyticsOverviewUrl($this->tenant, ['from' => '2026-08-10', 'to' => '2026-08-10']),
    )->assertOk()->assertJsonPath('data.flows.total', 5);

    $this->actingAs($ownerB)->getJson(
        analyticsOverviewUrl($tenantB, ['from' => '2026-08-10', 'to' => '2026-08-10']),
    )->assertOk()->assertJsonPath('data.flows.total', 15);
})->group('AN-MT-U3-03');

it('AN-MT-U3-04: agent denied across tenants', function (): void {
    $this->actingAs($this->agent)->getJson(analyticsOverviewUrl($this->tenant))
        ->assertStatus(403);
})->group('AN-MT-U3-04');

it('AN-MT-U3-05: cross-tenant URL returns 404', function (): void {
    $tenantB = Tenant::factory()->create(['timezone' => 'UTC']);
    $ownerB = User::factory()->create();
    make_tenant_member($ownerB, $tenantB, 'owner');

    $this->actingAs($this->owner)->getJson(analyticsOverviewUrl($tenantB))
        ->assertStatus(404);
})->group('AN-MT-U3-05');

it('AN-MT-U3-06: TenantContext mismatch does not leak', function (): void {
    $tenantB = Tenant::factory()->create(['timezone' => 'UTC']);
    $ownerB = User::factory()->create();
    make_tenant_member($ownerB, $tenantB, 'owner');

    seedAnalyticsRow($this->tenant, now()->toDateString(), ['total_ai_tokens' => 500]);
    seedAnalyticsRow($tenantB, now()->toDateString(), ['total_ai_tokens' => 999]);

    $response = $this->actingAs($this->owner)->getJson(analyticsOverviewUrl($this->tenant));
    $response->assertOk()->assertJsonPath('data.ai.total_tokens', 500);
})->group('AN-MT-U3-06');

it('AN-MT-U3-07: malformed tenant UUID does not reveal data', function (): void {
    $response = $this->actingAs($this->owner)->getJson(
        '/api/v1/tenants/not-a-uuid/analytics/overview',
    );

    $response->assertStatus(404);
})->group('AN-MT-U3-07');

it('AN-MT-U3-08: inactive membership denied', function (): void {
    $inactive = User::factory()->create();
    $this->tenant->users()->attach($inactive, [
        'role' => 'agent',
        'status' => 'inactive',
        'joined_at' => now(),
    ]);

    $this->actingAs($inactive)->getJson(analyticsOverviewUrl($this->tenant))
        ->assertStatus(403);
})->group('AN-MT-U3-08');
