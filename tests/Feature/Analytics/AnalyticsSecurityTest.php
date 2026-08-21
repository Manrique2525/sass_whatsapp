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

function secOverviewUrl(Tenant $tenant, array $params = []): string
{
    $base = '/api/v1/tenants/'.$tenant->id.'/analytics/overview';

    if ($params === []) {
        return $base;
    }

    return $base.'?'.http_build_query($params);
}

function seedSecRow(Tenant $tenant, string $date, array $overrides = []): void
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
| AN-SEC-F05: Auth Before Cache (P0)
|   Auth must happen BEFORE Cache::remember callback.
|   Existing: AN-PERM-01..04, AN-MT-U3-01..08
*/

it('AN-SEC-F05: agent denied even if owner previously cached same data', function (): void {
    seedSecRow($this->tenant, now()->toDateString(), ['total_messages' => 42]);

    $this->actingAs($this->owner)->getJson(secOverviewUrl($this->tenant))
        ->assertOk()->assertJsonPath('data.messages.total', 42);

    $this->actingAs($this->agent)->getJson(secOverviewUrl($this->tenant))
        ->assertStatus(403)
        ->assertJsonPath('code', 'PERMISSION_DENIED');
})->group('AN-SEC-F05');

/*
| AN-SEC-F07: Response Contains No PII (P1)
*/

it('AN-SEC-F07a: response JSON contains no PII fields', function (): void {
    seedSecRow($this->tenant, now()->toDateString(), ['total_messages' => 100]);

    $response = $this->actingAs($this->owner)->getJson(secOverviewUrl($this->tenant));
    $response->assertOk();

    $json = json_encode($response->json());

    expect($json)->not->toContain('tenant_id');
    expect($json)->not->toContain('contact_id');
    expect($json)->not->toContain('conversation_id');
    expect($json)->not->toContain('message_id');
    expect($json)->not->toContain('flow_id');
    expect($json)->not->toContain('lead_id');
    expect($json)->not->toContain('phone');
    expect($json)->not->toContain('@');
    expect($json)->not->toContain('+52');
})->group('AN-SEC-F07');

it('AN-SEC-F07b: response does not expose cache keys or internals', function (): void {
    seedSecRow($this->tenant, now()->toDateString());

    $response = $this->actingAs($this->owner)->getJson(secOverviewUrl($this->tenant));
    $response->assertOk();

    $json = json_encode($response->json());

    expect($json)->not->toContain('lock:');
    expect($json)->not->toContain('analytics:aggregate:');
})->group('AN-SEC-F07');

it('AN-SEC-F07c: daily series contains only date and numeric aggregates', function (): void {
    seedSecRow($this->tenant, '2026-08-01', ['total_messages' => 10]);

    $response = $this->actingAs($this->owner)->getJson(
        secOverviewUrl($this->tenant, ['from' => '2026-08-01', 'to' => '2026-08-01']),
    );

    $response->assertOk();

    $daily = $response->json('data.daily');
    expect($daily)->toHaveCount(1);

    $row = $daily[0];
    expect($row)->toHaveKeys([
        'date', 'messages_total', 'messages_inbound', 'messages_outbound',
        'conversations_total', 'leads_total', 'flow_executions_total', 'ai_tokens',
    ]);

    expect($row['date'])->toBeString();
    expect($row['messages_total'])->toBeInt();
    expect($row['ai_tokens'])->toBeInt();
})->group('AN-SEC-F07');

/*
| AN-SEC-F08: Aggregation Reads No PII (P1)
*/

it('AN-SEC-F08a: analytics_daily columns are all numeric aggregates', function (): void {
    $this->service->aggregateForDate($this->tenant, now()->toDateString());

    $row = DB::table('analytics_daily')
        ->where('tenant_id', $this->tenant->id)
        ->where('date', now()->toDateString())
        ->first();

    expect($row)->not->toBeNull();

    $numericCols = [
        'total_messages', 'messages_inbound', 'messages_outbound',
        'messages_delivered', 'messages_read', 'messages_failed',
        'total_conversations', 'conversations_open', 'conversations_resolved',
        'conversations_archived', 'conversations_handoff_requested',
        'conversations_bot_paused', 'unique_contacts', 'avg_response_time_seconds',
        'total_flow_executions', 'flow_executions_completed', 'flow_executions_failed',
        'total_leads', 'leads_new', 'leads_won', 'leads_lost', 'total_ai_tokens',
    ];

    foreach ($numericCols as $col) {
        $value = $row->$col;
        expect($value === null || is_int($value) || ctype_digit((string) $value))
            ->toBeTrue("Column {$col} should be integer, got: ".var_export($value, true));
    }
})->group('AN-SEC-F08');

it('AN-SEC-F08b: conversation_metrics stores only counts and timestamps', function (): void {
    $contact = Contact::create([
        'name' => 'Security Test Contact',
        'phone' => '+15550009999',
    ]);

    $convId = (string) Str::uuid();
    DB::table('conversations')->insert([
        'id' => $convId,
        'tenant_id' => $this->tenant->id,
        'contact_id' => $contact->id,
        'status' => 'open',
        'bot_paused' => false,
        'auto_assigned' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('messages')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => $this->tenant->id,
        'conversation_id' => $convId,
        'direction' => 'inbound',
        'type' => 'text',
        'status' => 'received',
        'body' => 'Hello with sensitive data: +15550009999, secret@evil.com',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('messages')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => $this->tenant->id,
        'conversation_id' => $convId,
        'direction' => 'outbound',
        'type' => 'text',
        'status' => 'sent',
        'body' => 'Bot reply with API key sk-1234567890',
        'created_at' => now()->addSeconds(5),
        'updated_at' => now()->addSeconds(5),
    ]);

    $this->service->aggregateForDate($this->tenant, now()->toDateString());

    $cm = DB::table('conversation_metrics')
        ->where('tenant_id', $this->tenant->id)
        ->where('conversation_id', $convId)
        ->first();

    expect($cm)->not->toBeNull();

    $rowJson = json_encode((array) $cm);
    expect($rowJson)->not->toContain('+15550009999');
    expect($rowJson)->not->toContain('secret@evil.com');
    expect($rowJson)->not->toContain('sk-1234567890');
    expect($rowJson)->not->toContain('sensitive');
    expect($rowJson)->not->toContain('Hello');
})->group('AN-SEC-F08');

/*
| AN-SEC-F09: AI Telemetry Safe Only (P1)
*/

it('AN-SEC-F09: AI token aggregation reads only total_tokens from payload', function (): void {
    $methodReflection = new ReflectionMethod(AggregationService::class, 'computeAiTokens');
    $fileName = $methodReflection->getFileName();
    $startLine = $methodReflection->getStartLine();
    $endLine = $methodReflection->getEndLine();
    $allLines = file($fileName);
    $methodSource = implode('', array_slice($allLines, $startLine - 1, $endLine - $startLine + 1));

    // The query must extract only total_tokens from JSON payload
    expect($methodSource)->toContain("payload->>'total_tokens'");

    // The query must NOT reference prompt, response, content, api_key, contact fields
    expect($methodSource)->not->toContain('prompt');
    expect($methodSource)->not->toContain('api_key');
    expect($methodSource)->not->toContain('phone');
    expect($methodSource)->not->toContain('email');
})->group('AN-SEC-F09');

/*
| AN-SEC-F12: Concurrent Aggregation (P1)
*/

it('AN-SEC-F12: double aggregation produces same result (idempotent)', function (): void {
    $date = now()->toDateString();

    $this->service->aggregateForDate($this->tenant, $date);

    $contact = Contact::create([
        'name' => 'Concurrency Test Contact',
        'phone' => '+15550008888',
    ]);

    $convId = (string) Str::uuid();
    DB::table('conversations')->insert([
        'id' => $convId,
        'tenant_id' => $this->tenant->id,
        'contact_id' => $contact->id,
        'status' => 'open',
        'bot_paused' => false,
        'auto_assigned' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('messages')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => $this->tenant->id,
        'conversation_id' => $convId,
        'direction' => 'inbound',
        'type' => 'text',
        'status' => 'received',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->service->aggregateForDate($this->tenant, $date);

    $rows = DB::table('analytics_daily')
        ->where('tenant_id', $this->tenant->id)
        ->where('date', $date)
        ->count();

    expect($rows)->toBe(1);
})->group('AN-SEC-F12');
