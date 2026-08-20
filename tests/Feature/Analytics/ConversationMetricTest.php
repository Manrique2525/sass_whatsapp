<?php

declare(strict_types=1);

use App\Domain\Analytics\Models\AnalyticsDaily;
use App\Domain\Analytics\Models\ConversationMetric;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
});

/*
|--------------------------------------------------------------------------
| ConversationMetric Model Tests (FASE 21 U1)
|--------------------------------------------------------------------------
|
| AN-DOM-11..20 — Domain invariants for conversation_metrics.
| Corren en SQLite :memory:.
|
*/

function createTestConversationForMetric(Tenant $tenant): Conversation
{
    $contact = Contact::create([
        'name' => 'Metric Contact '.Str::random(6),
        'phone' => '+'.Str::random(12),
    ]);

    return Conversation::create([
        'contact_id' => $contact->id,
        'status' => 'open',
    ]);
}

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->user = User::factory()->create();
    make_tenant_member($this->user, $this->tenant, 'owner');
    TenantContext::setId($this->tenant->id);
});

it('AN-DOM-11: ConversationMetric can be created', function (): void {
    $conversation = createTestConversationForMetric($this->tenant);

    $metric = ConversationMetric::factory()->create([
        'tenant_id' => $this->tenant->id,
        'conversation_id' => $conversation->id,
    ]);

    $this->assertNotNull($metric->id);
    $this->assertEquals($this->tenant->id, $metric->tenant_id);
    $this->assertEquals($conversation->id, $metric->conversation_id);
})->group('AN-DOM-11');

it('AN-DOM-12: tenant_id is auto-assigned from TenantContext', function (): void {
    $conversation = createTestConversationForMetric($this->tenant);

    $metric = ConversationMetric::factory()->create([
        'conversation_id' => $conversation->id,
    ]);

    $this->assertEquals($this->tenant->id, $metric->tenant_id);
})->group('AN-DOM-12');

it('AN-DOM-13: tenant_id is NOT mass assignable', function (): void {
    $conversation = createTestConversationForMetric($this->tenant);

    $metric = ConversationMetric::factory()->create([
        'conversation_id' => $conversation->id,
    ]);

    $metric->fill(['tenant_id' => 'fake-tenant-id']);
    $this->assertEquals($this->tenant->id, $metric->tenant_id);
})->group('AN-DOM-13');

it('AN-DOM-14: timestamp casts are correct', function (): void {
    $conversation = createTestConversationForMetric($this->tenant);

    $metric = ConversationMetric::factory()->create([
        'tenant_id' => $this->tenant->id,
        'conversation_id' => $conversation->id,
        'first_response_at' => '2026-01-15 10:30:00',
        'last_message_at' => '2026-01-15 11:00:00',
        'resolved_at' => '2026-01-15 12:00:00',
        'handoff_at' => '2026-01-15 10:45:00',
    ]);

    $this->assertInstanceOf(Carbon::class, $metric->first_response_at);
    $this->assertInstanceOf(Carbon::class, $metric->last_message_at);
    $this->assertInstanceOf(Carbon::class, $metric->resolved_at);
    $this->assertInstanceOf(Carbon::class, $metric->handoff_at);
})->group('AN-DOM-14');

it('AN-DOM-15: boolean cast for handoff_requested', function (): void {
    $conversation1 = createTestConversationForMetric($this->tenant);
    $metricTrue = ConversationMetric::factory()->create([
        'tenant_id' => $this->tenant->id,
        'conversation_id' => $conversation1->id,
        'handoff_requested' => true,
    ]);
    $this->assertTrue($metricTrue->handoff_requested);

    $conversation2 = createTestConversationForMetric($this->tenant);
    $metricFalse = ConversationMetric::factory()->create([
        'tenant_id' => $this->tenant->id,
        'conversation_id' => $conversation2->id,
        'handoff_requested' => false,
    ]);
    $this->assertFalse($metricFalse->handoff_requested);
})->group('AN-DOM-15');

it('AN-DOM-16: integer casts for duration and count fields', function (): void {
    $conversation = createTestConversationForMetric($this->tenant);

    $metric = ConversationMetric::factory()->create([
        'tenant_id' => $this->tenant->id,
        'conversation_id' => $conversation->id,
        'response_time_seconds' => 45,
        'handle_time_seconds' => 300,
        'message_count' => 15,
        'bot_message_count' => 10,
        'agent_message_count' => 5,
    ]);

    $this->assertIsInt($metric->response_time_seconds);
    $this->assertEquals(45, $metric->response_time_seconds);
    $this->assertEquals(300, $metric->handle_time_seconds);
    $this->assertEquals(15, $metric->message_count);
    $this->assertEquals(10, $metric->bot_message_count);
    $this->assertEquals(5, $metric->agent_message_count);
})->group('AN-DOM-16');

it('AN-DOM-17: nullable fields accept null', function (): void {
    $conversation = createTestConversationForMetric($this->tenant);

    $metric = ConversationMetric::factory()->create([
        'tenant_id' => $this->tenant->id,
        'conversation_id' => $conversation->id,
        'first_response_at' => null,
        'resolved_at' => null,
        'response_time_seconds' => null,
        'handle_time_seconds' => null,
        'handoff_at' => null,
    ]);

    $this->assertNull($metric->first_response_at);
    $this->assertNull($metric->resolved_at);
    $this->assertNull($metric->response_time_seconds);
    $this->assertNull($metric->handle_time_seconds);
    $this->assertNull($metric->handoff_at);
})->group('AN-DOM-17');

it('AN-DOM-18: conversation relationship works', function (): void {
    $conversation = createTestConversationForMetric($this->tenant);

    $metric = ConversationMetric::factory()->create([
        'tenant_id' => $this->tenant->id,
        'conversation_id' => $conversation->id,
    ]);

    $this->assertNotNull($metric->conversation);
    $this->assertEquals($conversation->id, $metric->conversation->id);
})->group('AN-DOM-18');

it('AN-DOM-19: no soft deletes', function (): void {
    $conversation = createTestConversationForMetric($this->tenant);

    $metric = ConversationMetric::factory()->create([
        'tenant_id' => $this->tenant->id,
        'conversation_id' => $conversation->id,
    ]);

    $metric->delete();

    $this->assertDatabaseMissing('conversation_metrics', ['id' => $metric->id]);
})->group('AN-DOM-19');

it('AN-DOM-20: AnalyticsDaily no soft deletes', function (): void {
    $daily = AnalyticsDaily::factory()->create([
        'tenant_id' => $this->tenant->id,
        'date' => '2026-01-15',
    ]);

    $daily->delete();

    $this->assertDatabaseMissing('analytics_daily', ['id' => $daily->id]);
})->group('AN-DOM-20');
