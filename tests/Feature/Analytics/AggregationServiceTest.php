<?php

declare(strict_types=1);

use App\Application\Analytics\Services\AggregationService;
use App\Domain\Analytics\Models\AnalyticsDaily;
use App\Domain\Analytics\Models\ConversationMetric;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
});

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['timezone' => 'UTC']);
    TenantContext::setId($this->tenant->id);
    $this->service = app(AggregationService::class);
});

function aggContact(Tenant $tenant, string $suffix = ''): Contact
{
    return Contact::create([
        'name' => 'Contact '.$suffix,
        'phone' => '+1'.($suffix ?: Str::random(10)),
    ]);
}

function aggConversation(Tenant $tenant, string $contactId, array $overrides = []): string
{
    $id = (string) Str::uuid();
    DB::table('conversations')->insert(array_merge([
        'id' => $id,
        'tenant_id' => $tenant->id,
        'contact_id' => $contactId,
        'status' => 'open',
        'bot_paused' => false,
        'auto_assigned' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));

    return $id;
}

function aggMessage(string $conversationId, string $direction, array $overrides = []): void
{
    DB::table('messages')->insert(array_merge([
        'id' => (string) Str::uuid(),
        'tenant_id' => TenantContext::id(),
        'conversation_id' => $conversationId,
        'direction' => $direction,
        'type' => 'text',
        'status' => 'sent',
        'body' => 'Test',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

function aggFlow(Tenant $tenant): string
{
    $chatbotId = (string) Str::uuid();
    DB::table('chatbots')->insert([
        'id' => $chatbotId,
        'tenant_id' => $tenant->id,
        'name' => 'Test Bot',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $flowId = (string) Str::uuid();
    DB::table('flows')->insert([
        'id' => $flowId,
        'tenant_id' => $tenant->id,
        'chatbot_id' => $chatbotId,
        'name' => 'Test Flow',
        'status' => 'published',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $flowId;
}

it('AN-AGG-U01: computes correct window for UTC tenant', function (): void {
    $c = aggContact($this->tenant);
    $conv = aggConversation($this->tenant, $c->id);
    aggMessage($conv, 'inbound', ['created_at' => Carbon::parse('2026-08-20 15:00:00')]);

    $r = $this->service->aggregateForDate($this->tenant, '2026-08-20');
    $this->assertEquals(1, $r->total_messages);
    $this->assertEquals('2026-08-20', $r->date->format('Y-m-d'));
})->group('AN-AGG-U01');

it('AN-AGG-U02: returns zeroed metrics for empty dataset', function (): void {
    $r = $this->service->aggregateForDate($this->tenant, '2026-08-20');
    $this->assertEquals(0, $r->total_messages);
    $this->assertEquals(0, $r->total_conversations);
    $this->assertEquals(0, $r->unique_contacts);
    $this->assertEquals(0, $r->total_flow_executions);
    $this->assertEquals(0, $r->total_leads);
    $this->assertEquals(0, $r->total_ai_tokens);
    $this->assertNull($r->avg_response_time_seconds);
})->group('AN-AGG-U02');

it('AN-AGG-U03: counts inbound and outbound messages', function (): void {
    $c = aggContact($this->tenant);
    $conv = aggConversation($this->tenant, $c->id);
    aggMessage($conv, 'inbound', ['created_at' => Carbon::parse('2026-08-20 10:00')]);
    aggMessage($conv, 'inbound', ['created_at' => Carbon::parse('2026-08-20 10:05')]);
    aggMessage($conv, 'outbound', ['created_at' => Carbon::parse('2026-08-20 10:02')]);
    aggMessage($conv, 'outbound', ['created_at' => Carbon::parse('2026-08-20 10:10')]);
    aggMessage($conv, 'outbound', ['created_at' => Carbon::parse('2026-08-20 10:15')]);

    $r = $this->service->aggregateForDate($this->tenant, '2026-08-20');
    $this->assertEquals(5, $r->total_messages);
    $this->assertEquals(2, $r->messages_inbound);
    $this->assertEquals(3, $r->messages_outbound);
})->group('AN-AGG-U03');

it('AN-AGG-U04: counts delivered/read/failed by lifecycle timestamps', function (): void {
    $c = aggContact($this->tenant);
    $conv = aggConversation($this->tenant, $c->id);
    aggMessage($conv, 'outbound', [
        'created_at' => Carbon::parse('2026-08-20 10:00'),
        'delivered_at' => Carbon::parse('2026-08-20 10:01'),
        'read_at' => Carbon::parse('2026-08-20 10:05'),
    ]);
    aggMessage($conv, 'outbound', [
        'created_at' => Carbon::parse('2026-08-20 10:02'),
        'failed_at' => Carbon::parse('2026-08-20 10:03'),
    ]);
    aggMessage($conv, 'outbound', ['created_at' => Carbon::parse('2026-08-20 10:04')]);

    $r = $this->service->aggregateForDate($this->tenant, '2026-08-20');
    $this->assertEquals(3, $r->total_messages);
    $this->assertEquals(1, $r->messages_delivered);
    $this->assertEquals(1, $r->messages_read);
    $this->assertEquals(1, $r->messages_failed);
})->group('AN-AGG-U04');

it('AN-AGG-U05: counts conversations created in window with status snapshot', function (): void {
    $c = aggContact($this->tenant);
    $conv1 = aggConversation($this->tenant, $c->id, ['created_at' => Carbon::parse('2026-08-20 10:00')]);
    aggMessage($conv1, 'inbound', ['created_at' => Carbon::parse('2026-08-20 10:01')]);

    $conv2 = aggConversation($this->tenant, $c->id, ['status' => 'resolved', 'created_at' => Carbon::parse('2026-08-20 11:00')]);
    aggMessage($conv2, 'inbound', ['created_at' => Carbon::parse('2026-08-20 11:01')]);

    $conv3 = aggConversation($this->tenant, $c->id, [
        'status' => 'archived', 'handoff_requested_at' => Carbon::parse('2026-08-20 12:00'),
        'bot_paused' => true, 'created_at' => Carbon::parse('2026-08-20 12:00'),
    ]);
    aggMessage($conv3, 'inbound', ['created_at' => Carbon::parse('2026-08-20 12:01')]);

    // Outside window
    $conv4 = aggConversation($this->tenant, $c->id, ['created_at' => Carbon::parse('2026-08-19 10:00')]);
    aggMessage($conv4, 'inbound', ['created_at' => Carbon::parse('2026-08-20 14:00')]);

    $r = $this->service->aggregateForDate($this->tenant, '2026-08-20');
    $this->assertEquals(3, $r->total_conversations);
    $this->assertEquals(1, $r->conversations_open);
    $this->assertEquals(1, $r->conversations_resolved);
    $this->assertEquals(1, $r->conversations_archived);
    $this->assertEquals(1, $r->conversations_handoff_requested);
    $this->assertEquals(1, $r->conversations_bot_paused);
})->group('AN-AGG-U05');

it('AN-AGG-U06: counts unique contacts from conversations with messages', function (): void {
    $c1 = aggContact($this->tenant, '1');
    $c2 = aggContact($this->tenant, '2');
    $c3 = aggContact($this->tenant, '3');
    $conv1 = aggConversation($this->tenant, $c1->id);
    $conv2 = aggConversation($this->tenant, $c2->id);
    $conv3 = aggConversation($this->tenant, $c3->id);
    aggMessage($conv1, 'inbound', ['created_at' => Carbon::parse('2026-08-20 10:00')]);
    aggMessage($conv2, 'inbound', ['created_at' => Carbon::parse('2026-08-20 11:00')]);
    aggMessage($conv3, 'inbound', ['created_at' => Carbon::parse('2026-08-19 10:00')]);

    $r = $this->service->aggregateForDate($this->tenant, '2026-08-20');
    $this->assertEquals(2, $r->unique_contacts);
})->group('AN-AGG-U06');

it('AN-AGG-U07: counts flow executions by status', function (): void {
    $c = aggContact($this->tenant);
    $conv = aggConversation($this->tenant, $c->id);
    $flowId = aggFlow($this->tenant);
    $now = Carbon::parse('2026-08-20 10:00');

    DB::table('flow_executions')->insert([
        ['id' => Str::uuid(), 'tenant_id' => $this->tenant->id, 'flow_id' => $flowId, 'conversation_id' => $conv, 'status' => 'completed', 'variables' => '{}', 'created_at' => $now, 'updated_at' => $now],
        ['id' => Str::uuid(), 'tenant_id' => $this->tenant->id, 'flow_id' => $flowId, 'conversation_id' => $conv, 'status' => 'failed', 'variables' => '{}', 'created_at' => $now->copy()->addHour(), 'updated_at' => $now->copy()->addHour()],
        ['id' => Str::uuid(), 'tenant_id' => $this->tenant->id, 'flow_id' => $flowId, 'conversation_id' => $conv, 'status' => 'running', 'variables' => '{}', 'created_at' => $now->copy()->addHours(2), 'updated_at' => $now->copy()->addHours(2)],
    ]);

    $r = $this->service->aggregateForDate($this->tenant, '2026-08-20');
    $this->assertEquals(3, $r->total_flow_executions);
    $this->assertEquals(1, $r->flow_executions_completed);
    $this->assertEquals(1, $r->flow_executions_failed);
})->group('AN-AGG-U07');

it('AN-AGG-U08: counts leads created in window by status snapshot', function (): void {
    $now = Carbon::parse('2026-08-20 10:00');
    DB::table('leads')->insert([
        ['id' => Str::uuid(), 'tenant_id' => $this->tenant->id, 'name' => 'New', 'status' => 'new', 'created_at' => $now, 'updated_at' => $now],
        ['id' => Str::uuid(), 'tenant_id' => $this->tenant->id, 'name' => 'Won', 'status' => 'won', 'created_at' => $now->copy()->addHour(), 'updated_at' => $now->copy()->addHour()],
        ['id' => Str::uuid(), 'tenant_id' => $this->tenant->id, 'name' => 'Lost', 'status' => 'lost', 'created_at' => $now->copy()->addHours(2), 'updated_at' => $now->copy()->addHours(2)],
        ['id' => Str::uuid(), 'tenant_id' => $this->tenant->id, 'name' => 'Old', 'status' => 'contacted', 'created_at' => $now->copy()->subDay(), 'updated_at' => $now->copy()->subDay()],
    ]);

    $r = $this->service->aggregateForDate($this->tenant, '2026-08-20');
    $this->assertEquals(3, $r->total_leads);
    $this->assertEquals(1, $r->leads_new);
    $this->assertEquals(1, $r->leads_won);
    $this->assertEquals(1, $r->leads_lost);
})->group('AN-AGG-U08');

it('AN-AGG-U09: sums AI tokens from ai_completed logs', function (): void {
    $c = aggContact($this->tenant);
    $conv = aggConversation($this->tenant, $c->id);
    $flowId = aggFlow($this->tenant);
    $execId = Str::uuid();
    $now = Carbon::parse('2026-08-20 10:00');

    DB::table('flow_executions')->insert([
        'id' => $execId, 'tenant_id' => $this->tenant->id, 'flow_id' => $flowId,
        'conversation_id' => $conv, 'status' => 'completed', 'variables' => '{}',
        'created_at' => $now, 'updated_at' => $now,
    ]);

    DB::table('flow_execution_logs')->insert([
        ['id' => Str::uuid(), 'tenant_id' => $this->tenant->id, 'execution_id' => $execId, 'event' => 'ai_completed', 'payload' => json_encode(['total_tokens' => 150]), 'sequence' => 1, 'created_at' => $now],
        ['id' => Str::uuid(), 'tenant_id' => $this->tenant->id, 'execution_id' => $execId, 'event' => 'ai_completed', 'payload' => json_encode(['total_tokens' => 250]), 'sequence' => 2, 'created_at' => $now],
        ['id' => Str::uuid(), 'tenant_id' => $this->tenant->id, 'execution_id' => $execId, 'event' => 'ai_failed', 'payload' => json_encode(['total_tokens' => null]), 'sequence' => 3, 'created_at' => $now],
    ]);

    $r = $this->service->aggregateForDate($this->tenant, '2026-08-20');
    $this->assertEquals(400, $r->total_ai_tokens);
})->group('AN-AGG-U09');

it('AN-AGG-U10: avg response time is NULL when no responses', function (): void {
    $c = aggContact($this->tenant);
    $conv = aggConversation($this->tenant, $c->id);
    aggMessage($conv, 'inbound', ['created_at' => Carbon::parse('2026-08-20 10:00')]);

    $r = $this->service->aggregateForDate($this->tenant, '2026-08-20');
    $this->assertNull($r->avg_response_time_seconds);
})->group('AN-AGG-U10');

it('AN-AGG-U11: computes correct avg response time across conversations', function (): void {
    $c1 = aggContact($this->tenant, 'a');
    $c2 = aggContact($this->tenant, 'b');
    $conv1 = aggConversation($this->tenant, $c1->id);
    $conv2 = aggConversation($this->tenant, $c2->id);

    // Conv1: inbound at 10:00, outbound at 10:10 → 600s
    aggMessage($conv1, 'inbound', ['created_at' => Carbon::parse('2026-08-20 10:00')]);
    aggMessage($conv1, 'outbound', ['created_at' => Carbon::parse('2026-08-20 10:10')]);

    // Conv2: inbound at 11:00, outbound at 11:05 → 300s
    aggMessage($conv2, 'inbound', ['created_at' => Carbon::parse('2026-08-20 11:00')]);
    aggMessage($conv2, 'outbound', ['created_at' => Carbon::parse('2026-08-20 11:05')]);

    $r = $this->service->aggregateForDate($this->tenant, '2026-08-20');
    $this->assertEquals(450, $r->avg_response_time_seconds);
})->group('AN-AGG-U11');

it('AN-AGG-U12: repeat aggregate is idempotent (UPSERT)', function (): void {
    $c = aggContact($this->tenant);
    $conv = aggConversation($this->tenant, $c->id);
    aggMessage($conv, 'inbound', ['created_at' => Carbon::parse('2026-08-20 10:00')]);
    aggMessage($conv, 'outbound', ['created_at' => Carbon::parse('2026-08-20 10:05')]);

    $r1 = $this->service->aggregateForDate($this->tenant, '2026-08-20');
    $r2 = $this->service->aggregateForDate($this->tenant, '2026-08-20');

    $this->assertEquals($r1->id, $r2->id);
    $this->assertEquals($r1->total_messages, $r2->total_messages);
    $this->assertEquals(1, AnalyticsDaily::where('tenant_id', $this->tenant->id)->where('date', '2026-08-20')->count());
})->group('AN-AGG-U12');

/*
|---------------------------------------------------------------------------
| ConversationMetric materialization tests (AN-CM-01..10)
|---------------------------------------------------------------------------
*/

it('AN-CM-01: computes first_response_at as first outbound message', function (): void {
    $c = aggContact($this->tenant);
    $conv = aggConversation($this->tenant, $c->id);
    aggMessage($conv, 'inbound', ['created_at' => Carbon::parse('2026-08-20 10:00')]);
    aggMessage($conv, 'outbound', ['created_at' => Carbon::parse('2026-08-20 10:05')]);
    aggMessage($conv, 'outbound', ['created_at' => Carbon::parse('2026-08-20 10:10')]);

    $this->service->aggregateForDate($this->tenant, '2026-08-20');
    $cm = ConversationMetric::where('conversation_id', $conv)->first();

    $this->assertNotNull($cm);
    $this->assertEquals('2026-08-20 10:05:00', $cm->first_response_at->format('Y-m-d H:i:s'));
})->group('AN-CM-01');

it('AN-CM-02: first_response_at is NULL when no outbound', function (): void {
    $c = aggContact($this->tenant);
    $conv = aggConversation($this->tenant, $c->id);
    aggMessage($conv, 'inbound', ['created_at' => Carbon::parse('2026-08-20 10:00')]);

    $this->service->aggregateForDate($this->tenant, '2026-08-20');
    $cm = ConversationMetric::where('conversation_id', $conv)->first();

    $this->assertNotNull($cm);
    $this->assertNull($cm->first_response_at);
})->group('AN-CM-02');

it('AN-CM-03: response_time_seconds is never negative', function (): void {
    $c = aggContact($this->tenant);
    $conv = aggConversation($this->tenant, $c->id);
    // Outbound BEFORE inbound → should be NULL
    aggMessage($conv, 'outbound', ['created_at' => Carbon::parse('2026-08-20 10:00')]);
    aggMessage($conv, 'inbound', ['created_at' => Carbon::parse('2026-08-20 10:05')]);

    $this->service->aggregateForDate($this->tenant, '2026-08-20');
    $cm = ConversationMetric::where('conversation_id', $conv)->first();

    $this->assertNull($cm->response_time_seconds);
})->group('AN-CM-03');

it('AN-CM-04: last_message_at is latest message in window', function (): void {
    $c = aggContact($this->tenant);
    $conv = aggConversation($this->tenant, $c->id);
    aggMessage($conv, 'inbound', ['created_at' => Carbon::parse('2026-08-20 10:00')]);
    aggMessage($conv, 'outbound', ['created_at' => Carbon::parse('2026-08-20 10:15')]);

    $this->service->aggregateForDate($this->tenant, '2026-08-20');
    $cm = ConversationMetric::where('conversation_id', $conv)->first();

    $this->assertEquals('2026-08-20 10:15:00', $cm->last_message_at->format('Y-m-d H:i:s'));
})->group('AN-CM-04');

it('AN-CM-05: resolved_at and handle_time_seconds are NULL (no transition history)', function (): void {
    $c = aggContact($this->tenant);
    $conv = aggConversation($this->tenant, $c->id, ['status' => 'resolved']);
    aggMessage($conv, 'inbound', ['created_at' => Carbon::parse('2026-08-20 10:00')]);

    $this->service->aggregateForDate($this->tenant, '2026-08-20');
    $cm = ConversationMetric::where('conversation_id', $conv)->first();

    $this->assertNull($cm->resolved_at);
    $this->assertNull($cm->handle_time_seconds);
})->group('AN-CM-05');

it('AN-CM-06: handle_time_seconds is NULL for unresolved conversations', function (): void {
    $c = aggContact($this->tenant);
    $conv = aggConversation($this->tenant, $c->id, ['status' => 'open']);
    aggMessage($conv, 'inbound', ['created_at' => Carbon::parse('2026-08-20 10:00')]);

    $this->service->aggregateForDate($this->tenant, '2026-08-20');
    $cm = ConversationMetric::where('conversation_id', $conv)->first();

    $this->assertNull($cm->handle_time_seconds);
})->group('AN-CM-06');

it('AN-CM-07: bot_message_count excludes agent messages', function (): void {
    $c = aggContact($this->tenant);
    $conv = aggConversation($this->tenant, $c->id);
    $user = User::factory()->create();

    aggMessage($conv, 'outbound', ['created_at' => Carbon::parse('2026-08-20 10:00')]);
    aggMessage($conv, 'outbound', ['created_at' => Carbon::parse('2026-08-20 10:01')]);
    aggMessage($conv, 'outbound', [
        'created_at' => Carbon::parse('2026-08-20 10:02'),
        'sent_by_user_id' => $user->id,
    ]);

    $this->service->aggregateForDate($this->tenant, '2026-08-20');
    $cm = ConversationMetric::where('conversation_id', $conv)->first();

    $this->assertEquals(2, $cm->bot_message_count);
    $this->assertEquals(1, $cm->agent_message_count);
})->group('AN-CM-07');

it('AN-CM-08: agent_message_count tracks human-sent outbound', function (): void {
    $c = aggContact($this->tenant);
    $conv = aggConversation($this->tenant, $c->id);
    $user = User::factory()->create();

    aggMessage($conv, 'outbound', [
        'created_at' => Carbon::parse('2026-08-20 10:00'),
        'sent_by_user_id' => $user->id,
    ]);

    $this->service->aggregateForDate($this->tenant, '2026-08-20');
    $cm = ConversationMetric::where('conversation_id', $conv)->first();

    $this->assertEquals(0, $cm->bot_message_count);
    $this->assertEquals(1, $cm->agent_message_count);
})->group('AN-CM-08');

it('AN-CM-09: handoff fields derived from conversation', function (): void {
    $c = aggContact($this->tenant);
    $conv = aggConversation($this->tenant, $c->id, [
        'handoff_requested_at' => Carbon::parse('2026-08-20 12:00'),
        'bot_paused' => true,
    ]);
    aggMessage($conv, 'inbound', ['created_at' => Carbon::parse('2026-08-20 10:00')]);

    $this->service->aggregateForDate($this->tenant, '2026-08-20');
    $cm = ConversationMetric::where('conversation_id', $conv)->first();

    $this->assertTrue($cm->handoff_requested);
    $this->assertEquals('2026-08-20 12:00:00', $cm->handoff_at->format('Y-m-d H:i:s'));
})->group('AN-CM-09');

it('AN-CM-10: conversation_metric upsert is idempotent', function (): void {
    $c = aggContact($this->tenant);
    $conv = aggConversation($this->tenant, $c->id);
    aggMessage($conv, 'inbound', ['created_at' => Carbon::parse('2026-08-20 10:00')]);
    aggMessage($conv, 'outbound', ['created_at' => Carbon::parse('2026-08-20 10:05')]);

    $this->service->aggregateForDate($this->tenant, '2026-08-20');
    $this->service->aggregateForDate($this->tenant, '2026-08-20');

    $this->assertEquals(1, ConversationMetric::where('conversation_id', $conv)->count());
})->group('AN-CM-10');

/*
|---------------------------------------------------------------------------
| aggregateForTenant tests
|---------------------------------------------------------------------------
*/

it('AN-AGG-U13: aggregateForTenant iterates date range', function (): void {
    $c = aggContact($this->tenant);
    $conv = aggConversation($this->tenant, $c->id);
    aggMessage($conv, 'inbound', ['created_at' => Carbon::parse('2026-08-20 10:00')]);
    aggMessage($conv, 'inbound', ['created_at' => Carbon::parse('2026-08-21 10:00')]);

    $results = $this->service->aggregateForTenant($this->tenant->id, '2026-08-20', '2026-08-21');

    $this->assertCount(2, $results);
    $this->assertEquals(1, $results->first()->total_messages);
    $this->assertEquals(1, $results->last()->total_messages);
})->group('AN-AGG-U13');

it('AN-AGG-U14: aggregateForTenant caps at 365 days', function (): void {
    $results = $this->service->aggregateForTenant($this->tenant->id, '2026-01-01', '2026-12-31');
    $this->assertCount(365, $results);
})->group('AN-AGG-U14');

it('AN-AGG-U15: aggregateForTenant returns empty for nonexistent tenant', function (): void {
    $results = $this->service->aggregateForTenant('nonexistent-tenant-id', '2026-08-20', '2026-08-20');
    $this->assertCount(0, $results);
})->group('AN-AGG-U15');

it('AN-AGG-U16: messages outside window are excluded', function (): void {
    $c = aggContact($this->tenant);
    $conv = aggConversation($this->tenant, $c->id);
    aggMessage($conv, 'inbound', ['created_at' => Carbon::parse('2026-08-19 23:59:59')]);
    aggMessage($conv, 'inbound', ['created_at' => Carbon::parse('2026-08-20 00:00:00')]);
    aggMessage($conv, 'inbound', ['created_at' => Carbon::parse('2026-08-20 23:59:59')]);
    aggMessage($conv, 'inbound', ['created_at' => Carbon::parse('2026-08-21 00:00:00')]);

    $r = $this->service->aggregateForDate($this->tenant, '2026-08-20');
    $this->assertEquals(2, $r->total_messages);
})->group('AN-AGG-U16');

/*
|---------------------------------------------------------------------------
| Multi-tenancy isolation tests (AN-MT-U2-01..08)
|---------------------------------------------------------------------------
*/

it('AN-MT-U2-01: tenant A metrics exclude tenant B', function (): void {
    $tenantB = Tenant::factory()->create(['timezone' => 'UTC']);

    TenantContext::setId($this->tenant->id);
    $cA = aggContact($this->tenant, 'A');
    $convA = aggConversation($this->tenant, $cA->id);
    aggMessage($convA, 'inbound', ['created_at' => Carbon::parse('2026-08-20 10:00')]);

    TenantContext::setId($tenantB->id);
    $cB = aggContact($tenantB, 'B');
    $convB = aggConversation($tenantB, $cB->id);
    aggMessage($convB, 'inbound', ['created_at' => Carbon::parse('2026-08-20 10:00')]);

    TenantContext::setId($this->tenant->id);
    $rA = $this->service->aggregateForDate($this->tenant, '2026-08-20');
    $rB = $this->service->aggregateForDate($tenantB, '2026-08-20');

    $this->assertEquals(1, $rA->total_messages);
    $this->assertEquals(1, $rB->total_messages);
    $this->assertEquals($this->tenant->id, $rA->tenant_id);
    $this->assertEquals($tenantB->id, $rB->tenant_id);
})->group('AN-MT-U2-01');

it('AN-MT-U2-02: conversation metrics are tenant-scoped', function (): void {
    $tenantB = Tenant::factory()->create(['timezone' => 'UTC']);

    TenantContext::setId($this->tenant->id);
    $cA = aggContact($this->tenant, 'A');
    $convA = aggConversation($this->tenant, $cA->id);
    aggMessage($convA, 'inbound', ['created_at' => Carbon::parse('2026-08-20 10:00')]);

    TenantContext::setId($tenantB->id);
    $cB = aggContact($tenantB, 'B');
    $convB = aggConversation($tenantB, $cB->id);
    aggMessage($convB, 'inbound', ['created_at' => Carbon::parse('2026-08-20 10:00')]);

    TenantContext::setId($this->tenant->id);
    $this->service->aggregateForDate($this->tenant, '2026-08-20');
    $this->service->aggregateForDate($tenantB, '2026-08-20');

    $this->assertEquals(1, ConversationMetric::withoutTenantScope()->where('tenant_id', $this->tenant->id)->count());
    $this->assertEquals(1, ConversationMetric::withoutTenantScope()->where('tenant_id', $tenantB->id)->count());
})->group('AN-MT-U2-02');

it('AN-MT-U2-03: flow metrics are tenant-scoped', function (): void {
    $tenantB = Tenant::factory()->create(['timezone' => 'UTC']);
    $cA = aggContact($this->tenant, 'A');
    $convA = aggConversation($this->tenant, $cA->id);
    $flowId = aggFlow($this->tenant);
    $now = Carbon::parse('2026-08-20 10:00');

    DB::table('flow_executions')->insert([
        ['id' => Str::uuid(), 'tenant_id' => $this->tenant->id, 'flow_id' => $flowId, 'conversation_id' => $convA, 'status' => 'completed', 'variables' => '{}', 'created_at' => $now, 'updated_at' => $now],
    ]);

    $rA = $this->service->aggregateForDate($this->tenant, '2026-08-20');
    $rB = $this->service->aggregateForDate($tenantB, '2026-08-20');

    $this->assertEquals(1, $rA->total_flow_executions);
    $this->assertEquals(0, $rB->total_flow_executions);
})->group('AN-MT-U2-03');

it('AN-MT-U2-04: lead metrics are tenant-scoped', function (): void {
    $tenantB = Tenant::factory()->create(['timezone' => 'UTC']);
    $now = Carbon::parse('2026-08-20 10:00');

    DB::table('leads')->insert([
        ['id' => Str::uuid(), 'tenant_id' => $this->tenant->id, 'name' => 'A Lead', 'status' => 'new', 'created_at' => $now, 'updated_at' => $now],
    ]);

    $rA = $this->service->aggregateForDate($this->tenant, '2026-08-20');
    $rB = $this->service->aggregateForDate($tenantB, '2026-08-20');

    $this->assertEquals(1, $rA->total_leads);
    $this->assertEquals(0, $rB->total_leads);
})->group('AN-MT-U2-04');

it('AN-MT-U2-05: AI tokens are tenant-scoped', function (): void {
    $tenantB = Tenant::factory()->create(['timezone' => 'UTC']);
    $c = aggContact($this->tenant);
    $conv = aggConversation($this->tenant, $c->id);
    $flowId = aggFlow($this->tenant);
    $execId = Str::uuid();
    $now = Carbon::parse('2026-08-20 10:00');

    DB::table('flow_executions')->insert([
        'id' => $execId, 'tenant_id' => $this->tenant->id, 'flow_id' => $flowId,
        'conversation_id' => $conv, 'status' => 'completed', 'variables' => '{}',
        'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('flow_execution_logs')->insert([
        'id' => Str::uuid(), 'tenant_id' => $this->tenant->id, 'execution_id' => $execId,
        'event' => 'ai_completed', 'payload' => json_encode(['total_tokens' => 500]),
        'sequence' => 1, 'created_at' => $now,
    ]);

    $rA = $this->service->aggregateForDate($this->tenant, '2026-08-20');
    $rB = $this->service->aggregateForDate($tenantB, '2026-08-20');

    $this->assertEquals(500, $rA->total_ai_tokens);
    $this->assertEquals(0, $rB->total_ai_tokens);
})->group('AN-MT-U2-05');

it('AN-MT-U2-06: sequential jobs A then B restore context', function (): void {
    $tenantB = Tenant::factory()->create(['timezone' => 'UTC']);

    TenantContext::setId($this->tenant->id);
    $cA = aggContact($this->tenant, 'A');
    $convA = aggConversation($this->tenant, $cA->id);
    aggMessage($convA, 'inbound', ['created_at' => Carbon::parse('2026-08-20 10:00')]);

    TenantContext::setId($tenantB->id);
    $cB = aggContact($tenantB, 'B');
    $convB = aggConversation($tenantB, $cB->id);
    aggMessage($convB, 'inbound', ['created_at' => Carbon::parse('2026-08-20 10:00')]);

    TenantContext::setId($this->tenant->id);
    $this->service->aggregateForDate($this->tenant, '2026-08-20');

    TenantContext::setId($tenantB->id);
    $this->service->aggregateForDate($tenantB, '2026-08-20');

    TenantContext::setId($this->tenant->id);
    $rA = AnalyticsDaily::withoutTenantScope()->where('tenant_id', $this->tenant->id)->where('date', '2026-08-20')->first();
    $rB = AnalyticsDaily::withoutTenantScope()->where('tenant_id', $tenantB->id)->where('date', '2026-08-20')->first();

    $this->assertEquals(1, $rA->total_messages);
    $this->assertEquals(1, $rB->total_messages);
})->group('AN-MT-U2-06');

it('AN-MT-U2-07: malformed tenant_id does not leak data', function (): void {
    $c = aggContact($this->tenant);
    $conv = aggConversation($this->tenant, $c->id);
    aggMessage($conv, 'inbound', ['created_at' => Carbon::parse('2026-08-20 10:00')]);

    $fakeTenant = Tenant::factory()->create(['timezone' => 'UTC']);
    $r = $this->service->aggregateForDate($fakeTenant, '2026-08-20');

    $this->assertEquals(0, $r->total_messages);
})->group('AN-MT-U2-07');

it('AN-MT-U2-08: unique_contacts excludes other tenant contacts', function (): void {
    $tenantB = Tenant::factory()->create(['timezone' => 'UTC']);

    TenantContext::setId($this->tenant->id);
    $cA = aggContact($this->tenant, 'A');
    $convA = aggConversation($this->tenant, $cA->id);
    aggMessage($convA, 'inbound', ['created_at' => Carbon::parse('2026-08-20 10:00')]);

    TenantContext::setId($tenantB->id);
    $cB = aggContact($tenantB, 'B');
    $convB = aggConversation($tenantB, $cB->id);
    aggMessage($convB, 'inbound', ['created_at' => Carbon::parse('2026-08-20 10:00')]);

    TenantContext::setId($this->tenant->id);
    $rA = $this->service->aggregateForDate($this->tenant, '2026-08-20');
    $this->assertEquals(1, $rA->unique_contacts);
})->group('AN-MT-U2-08');
