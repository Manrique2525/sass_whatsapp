<?php

declare(strict_types=1);

use App\Domain\Analytics\Enums\MetricGranularity;
use App\Domain\Analytics\Models\AnalyticsDaily;
use App\Domain\Tenants\Models\Tenant;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
});

/*
|--------------------------------------------------------------------------
| AnalyticsDaily Model Tests (FASE 21 U1)
|--------------------------------------------------------------------------
|
| AN-DOM-01..10 — Domain invariants for analytics_daily.
| Corren en SQLite :memory:.
|
*/

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    TenantContext::setId($this->tenant->id);
});

it('AN-DOM-01: AnalyticsDaily can be created', function (): void {
    $daily = AnalyticsDaily::factory()->create([
        'tenant_id' => $this->tenant->id,
        'date' => '2026-01-15',
    ]);

    $this->assertNotNull($daily->id);
    $this->assertEquals('2026-01-15', $daily->date->format('Y-m-d'));
    $this->assertEquals($this->tenant->id, $daily->tenant_id);
})->group('AN-DOM-01');

it('AN-DOM-02: tenant_id is auto-assigned from TenantContext', function (): void {
    $daily = AnalyticsDaily::factory()->create([
        'date' => '2026-01-15',
    ]);

    $this->assertEquals($this->tenant->id, $daily->tenant_id);
})->group('AN-DOM-02');

it('AN-DOM-03: tenant_id is NOT mass assignable', function (): void {
    $daily = AnalyticsDaily::factory()->create([
        'date' => '2026-01-15',
    ]);

    $daily->fill(['tenant_id' => 'fake-tenant-id']);
    $this->assertEquals($this->tenant->id, $daily->tenant_id);
})->group('AN-DOM-03');

it('AN-DOM-04: date is cast to date', function (): void {
    $daily = AnalyticsDaily::factory()->create([
        'date' => '2026-03-15',
    ]);

    $this->assertInstanceOf(Carbon::class, $daily->date);
    $this->assertEquals('2026-03-15', $daily->date->format('Y-m-d'));
})->group('AN-DOM-04');

it('AN-DOM-05: integer casts are correct', function (): void {
    $daily = AnalyticsDaily::factory()->create([
        'tenant_id' => $this->tenant->id,
        'date' => '2026-01-15',
        'total_messages' => 100,
        'messages_inbound' => 60,
        'messages_outbound' => 40,
        'messages_delivered' => 35,
        'messages_read' => 20,
        'messages_failed' => 5,
        'total_conversations' => 25,
        'conversations_open' => 10,
        'conversations_resolved' => 12,
        'conversations_archived' => 3,
        'conversations_handoff_requested' => 5,
        'conversations_bot_paused' => 2,
        'unique_contacts' => 30,
        'total_flow_executions' => 50,
        'flow_executions_completed' => 45,
        'flow_executions_failed' => 5,
        'total_leads' => 15,
        'leads_new' => 8,
        'leads_won' => 4,
        'leads_lost' => 3,
        'total_ai_tokens' => 50000,
    ]);

    $this->assertIsInt($daily->total_messages);
    $this->assertEquals(100, $daily->total_messages);
    $this->assertEquals(60, $daily->messages_inbound);
    $this->assertEquals(40, $daily->messages_outbound);
    $this->assertEquals(35, $daily->messages_delivered);
    $this->assertEquals(20, $daily->messages_read);
    $this->assertEquals(5, $daily->messages_failed);
    $this->assertEquals(25, $daily->total_conversations);
    $this->assertEquals(10, $daily->conversations_open);
    $this->assertEquals(12, $daily->conversations_resolved);
    $this->assertEquals(3, $daily->conversations_archived);
    $this->assertEquals(5, $daily->conversations_handoff_requested);
    $this->assertEquals(2, $daily->conversations_bot_paused);
    $this->assertEquals(30, $daily->unique_contacts);
    $this->assertEquals(50, $daily->total_flow_executions);
    $this->assertEquals(45, $daily->flow_executions_completed);
    $this->assertEquals(5, $daily->flow_executions_failed);
    $this->assertEquals(15, $daily->total_leads);
    $this->assertEquals(8, $daily->leads_new);
    $this->assertEquals(4, $daily->leads_won);
    $this->assertEquals(3, $daily->leads_lost);
    $this->assertEquals(50000, $daily->total_ai_tokens);
})->group('AN-DOM-05');

it('AN-DOM-06: nullable avg_response_time_seconds accepts null', function (): void {
    $daily = AnalyticsDaily::factory()->create([
        'tenant_id' => $this->tenant->id,
        'date' => '2026-01-15',
        'avg_response_time_seconds' => null,
    ]);

    $this->assertNull($daily->avg_response_time_seconds);
})->group('AN-DOM-06');

it('AN-DOM-07: nullable avg_response_time_seconds accepts integer', function (): void {
    $daily = AnalyticsDaily::factory()->create([
        'tenant_id' => $this->tenant->id,
        'date' => '2026-01-15',
        'avg_response_time_seconds' => 42,
    ]);

    $this->assertEquals(42, $daily->avg_response_time_seconds);
})->group('AN-DOM-07');

it('AN-DOM-08: MetricGranularity contains exactly daily/weekly/monthly', function (): void {
    $cases = MetricGranularity::cases();

    $this->assertCount(3, $cases);
    $this->assertEquals('daily', MetricGranularity::Daily->value);
    $this->assertEquals('weekly', MetricGranularity::Weekly->value);
    $this->assertEquals('monthly', MetricGranularity::Monthly->value);
})->group('AN-DOM-08');

it('AN-DOM-09: MetricGranularity label() returns correct labels', function (): void {
    $this->assertEquals('Daily', MetricGranularity::Daily->label());
    $this->assertEquals('Weekly', MetricGranularity::Weekly->label());
    $this->assertEquals('Monthly', MetricGranularity::Monthly->label());
})->group('AN-DOM-09');

it('AN-DOM-10: defaults are zero for all counters', function (): void {
    $daily = new AnalyticsDaily;

    $this->assertEquals(0, $daily->total_messages);
    $this->assertEquals(0, $daily->messages_inbound);
    $this->assertEquals(0, $daily->messages_outbound);
    $this->assertEquals(0, $daily->messages_delivered);
    $this->assertEquals(0, $daily->messages_read);
    $this->assertEquals(0, $daily->messages_failed);
    $this->assertEquals(0, $daily->total_conversations);
    $this->assertEquals(0, $daily->conversations_open);
    $this->assertEquals(0, $daily->conversations_resolved);
    $this->assertEquals(0, $daily->conversations_archived);
    $this->assertEquals(0, $daily->conversations_handoff_requested);
    $this->assertEquals(0, $daily->conversations_bot_paused);
    $this->assertEquals(0, $daily->unique_contacts);
    $this->assertNull($daily->avg_response_time_seconds);
    $this->assertEquals(0, $daily->total_flow_executions);
    $this->assertEquals(0, $daily->flow_executions_completed);
    $this->assertEquals(0, $daily->flow_executions_failed);
    $this->assertEquals(0, $daily->total_leads);
    $this->assertEquals(0, $daily->leads_new);
    $this->assertEquals(0, $daily->leads_won);
    $this->assertEquals(0, $daily->leads_lost);
    $this->assertEquals(0, $daily->total_ai_tokens);
})->group('AN-DOM-10');
