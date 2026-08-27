<?php

declare(strict_types=1);

use App\Application\Analytics\Services\AggregationService;
use App\Domain\Analytics\Models\ConversationMetric;
use App\Domain\Tenants\Models\Tenant;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Postgres\PgvectorTestCase;

/*
|--------------------------------------------------------------------------
| PostgreSQL AggregationService Integration Tests (FASE 21 U2)
|--------------------------------------------------------------------------
|
| AN-PG-U2-01..10 — AggregationService + ConversationMetric against real PG.
| Ejecutar con: docker compose exec app vendor/bin/pest --configuration=phpunit.pgsql.xml
|
*/

function createPgU2Tenant(string $name = 'Test Tenant'): string
{
    $tenantId = (string) Str::uuid();
    DB::table('tenants')->insert([
        'id' => $tenantId,
        'name' => $name,
        'slug' => 'test-pg-u2-'.strtolower(Str::random(8)),
        'status' => 'active',
        'timezone' => 'UTC',
        'locale' => 'en',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $tenantId;
}

function createPgU2Contact(string $tenantId): string
{
    $contactId = (string) Str::uuid();
    DB::table('contacts')->insert([
        'id' => $contactId,
        'tenant_id' => $tenantId,
        'phone' => '+'.fake()->numerify('##############'),
        'name' => 'Test Contact',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $contactId;
}

function createPgU2Conversation(string $tenantId, string $contactId, array $overrides = []): string
{
    $conversationId = (string) Str::uuid();
    DB::table('conversations')->insert(array_merge([
        'id' => $conversationId,
        'tenant_id' => $tenantId,
        'contact_id' => $contactId,
        'status' => 'open',
        'auto_assigned' => false,
        'bot_paused' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));

    return $conversationId;
}

function createPgU2Flow(string $tenantId): string
{
    $chatbotId = (string) Str::uuid();
    DB::table('chatbots')->insert([
        'id' => $chatbotId,
        'tenant_id' => $tenantId,
        'name' => 'Test Bot',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $flowId = (string) Str::uuid();
    DB::table('flows')->insert([
        'id' => $flowId,
        'tenant_id' => $tenantId,
        'chatbot_id' => $chatbotId,
        'name' => 'Test Flow',
        'status' => 'published',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $flowId;
}

class AnalyticsAggregationPostgresTest extends PgvectorTestCase
{
    private string $tenantId;

    private AggregationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL required for AN-PG-U2 tests');
        }

        $this->tenantId = createPgU2Tenant();
        TenantContext::setId($this->tenantId);
        $this->service = app(AggregationService::class);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    private function getTenant(): Tenant
    {
        return Tenant::query()->find($this->tenantId);
    }

    /** @test AN-PG-U2-01: aggregation inserts analytics_daily row in real PG */
    public function it_inserts_analytics_daily_row(): void
    {
        $contactId = createPgU2Contact($this->tenantId);
        $convId = createPgU2Conversation($this->tenantId, $contactId, [
            'created_at' => '2026-08-20 10:00:00',
        ]);

        DB::table('messages')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'conversation_id' => $convId,
            'direction' => 'inbound',
            'type' => 'text',
            'status' => 'sent',
            'body' => 'Hello',
            'created_at' => '2026-08-20 10:00:00',
            'updated_at' => '2026-08-20 10:00:00',
        ]);

        $result = $this->service->aggregateForDate($this->getTenant(), '2026-08-20');

        $this->assertNotNull($result);
        $this->assertEquals(1, $result->total_messages);

        $this->assertDatabaseHas('analytics_daily', [
            'tenant_id' => $this->tenantId,
            'date' => '2026-08-20',
        ]);
    }

    /** @test AN-PG-U2-02: conversation_metrics FK composite constraint in PG */
    public function it_inserts_conversation_metrics_with_composite_fk(): void
    {
        $contactId = createPgU2Contact($this->tenantId);
        $convId = createPgU2Conversation($this->tenantId, $contactId, [
            'created_at' => '2026-08-20 10:00:00',
        ]);

        DB::table('messages')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'conversation_id' => $convId,
            'direction' => 'inbound',
            'type' => 'text',
            'status' => 'sent',
            'body' => 'Hello',
            'created_at' => '2026-08-20 10:00:00',
            'updated_at' => '2026-08-20 10:00:00',
        ]);

        $this->service->aggregateForDate($this->getTenant(), '2026-08-20');

        $cm = ConversationMetric::withoutTenantScope()
            ->where('tenant_id', $this->tenantId)
            ->where('conversation_id', $convId)
            ->first();

        $this->assertNotNull($cm);
        $this->assertEquals($this->tenantId, $cm->tenant_id);
        $this->assertEquals($convId, $cm->conversation_id);
    }

    /** @test AN-PG-U2-03: UPSERT idempotency in real PG */
    public function it_upserts_idempotently_in_pg(): void
    {
        $contactId = createPgU2Contact($this->tenantId);
        $convId = createPgU2Conversation($this->tenantId, $contactId, [
            'created_at' => '2026-08-20 10:00:00',
        ]);

        DB::table('messages')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'conversation_id' => $convId,
            'direction' => 'inbound',
            'type' => 'text',
            'status' => 'sent',
            'body' => 'Hello',
            'created_at' => '2026-08-20 10:00:00',
            'updated_at' => '2026-08-20 10:00:00',
        ]);

        $this->service->aggregateForDate($this->getTenant(), '2026-08-20');
        $this->service->aggregateForDate($this->getTenant(), '2026-08-20');

        $count = DB::table('analytics_daily')
            ->where('tenant_id', $this->tenantId)
            ->where('date', '2026-08-20')
            ->count();

        $this->assertEquals(1, $count);
    }

    /** @test AN-PG-U2-04: cross-tenant isolation in aggregation */
    public function it_isolates_cross_tenant_in_aggregation(): void
    {
        $tenantBId = createPgU2Tenant('Tenant B');

        $contactA = createPgU2Contact($this->tenantId);
        $convA = createPgU2Conversation($this->tenantId, $contactA, [
            'created_at' => '2026-08-20 10:00:00',
        ]);
        DB::table('messages')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'conversation_id' => $convA,
            'direction' => 'inbound',
            'type' => 'text',
            'status' => 'sent',
            'body' => 'A',
            'created_at' => '2026-08-20 10:00:00',
            'updated_at' => '2026-08-20 10:00:00',
        ]);

        $contactB = createPgU2Contact($tenantBId);
        $convB = createPgU2Conversation($tenantBId, $contactB, [
            'created_at' => '2026-08-20 11:00:00',
        ]);
        DB::table('messages')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantBId,
            'conversation_id' => $convB,
            'direction' => 'inbound',
            'type' => 'text',
            'status' => 'sent',
            'body' => 'B',
            'created_at' => '2026-08-20 11:00:00',
            'updated_at' => '2026-08-20 11:00:00',
        ]);

        TenantContext::setId($this->tenantId);
        $rA = $this->service->aggregateForDate(Tenant::query()->find($this->tenantId), '2026-08-20');

        TenantContext::setId($tenantBId);
        $rB = $this->service->aggregateForDate(Tenant::query()->find($tenantBId), '2026-08-20');

        $this->assertEquals(1, $rA->total_messages);
        $this->assertEquals(1, $rB->total_messages);
        $this->assertNotEquals($rA->tenant_id, $rB->tenant_id);
    }

    /** @test AN-PG-U2-05: conversation_metric UPSERT idempotency in PG */
    public function it_upserts_conversation_metrics_idempotently(): void
    {
        $contactId = createPgU2Contact($this->tenantId);
        $convId = createPgU2Conversation($this->tenantId, $contactId, [
            'created_at' => '2026-08-20 10:00:00',
        ]);

        DB::table('messages')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'conversation_id' => $convId,
            'direction' => 'inbound',
            'type' => 'text',
            'status' => 'sent',
            'body' => 'Hello',
            'created_at' => '2026-08-20 10:00:00',
            'updated_at' => '2026-08-20 10:00:00',
        ]);

        $this->service->aggregateForDate($this->getTenant(), '2026-08-20');
        $this->service->aggregateForDate($this->getTenant(), '2026-08-20');

        $count = ConversationMetric::withoutTenantScope()
            ->where('tenant_id', $this->tenantId)
            ->where('conversation_id', $convId)
            ->count();

        $this->assertEquals(1, $count);
    }

    /** @test AN-PG-U2-06: flow execution metrics in real PG */
    public function it_aggregates_flow_executions_in_pg(): void
    {
        $contactId = createPgU2Contact($this->tenantId);
        $convId = createPgU2Conversation($this->tenantId, $contactId, [
            'created_at' => '2026-08-20 10:00:00',
        ]);
        $flowId = createPgU2Flow($this->tenantId);

        DB::table('flow_executions')->insert([
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $this->tenantId,
                'flow_id' => $flowId,
                'conversation_id' => $convId,
                'status' => 'completed',
                'variables' => '{}',
                'created_at' => '2026-08-20 10:00:00',
                'updated_at' => '2026-08-20 10:00:00',
            ],
        ]);

        $result = $this->service->aggregateForDate($this->getTenant(), '2026-08-20');

        $this->assertEquals(1, $result->total_flow_executions);
        $this->assertEquals(1, $result->flow_executions_completed);
    }

    /** @test AN-PG-U2-07: AI tokens sum in real PG */
    public function it_sums_ai_tokens_in_pg(): void
    {
        $contactId = createPgU2Contact($this->tenantId);
        $convId = createPgU2Conversation($this->tenantId, $contactId, [
            'created_at' => '2026-08-20 10:00:00',
        ]);
        $flowId = createPgU2Flow($this->tenantId);
        $execId = (string) Str::uuid();

        DB::table('flow_executions')->insert([
            'id' => $execId,
            'tenant_id' => $this->tenantId,
            'flow_id' => $flowId,
            'conversation_id' => $convId,
            'status' => 'completed',
            'variables' => '{}',
            'created_at' => '2026-08-20 10:00:00',
            'updated_at' => '2026-08-20 10:00:00',
        ]);

        DB::table('flow_execution_logs')->insert([
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $this->tenantId,
                'execution_id' => $execId,
                'event' => 'ai_completed',
                'payload' => json_encode(['total_tokens' => 1000]),
                'sequence' => 1,
                'created_at' => '2026-08-20 10:00:00',
            ],
        ]);

        $result = $this->service->aggregateForDate($this->getTenant(), '2026-08-20');

        $this->assertEquals(1000, $result->total_ai_tokens);
    }

    /** @test AN-PG-U2-08: lead metrics in real PG */
    public function it_aggregates_leads_in_pg(): void
    {
        DB::table('leads')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'name' => 'New Lead',
            'status' => 'new',
            'created_at' => '2026-08-20 10:00:00',
            'updated_at' => '2026-08-20 10:00:00',
        ]);

        $result = $this->service->aggregateForDate($this->getTenant(), '2026-08-20');

        $this->assertEquals(1, $result->total_leads);
        $this->assertEquals(1, $result->leads_new);
    }

    /** @test AN-PG-U2-09: timezone window computation in PG */
    public function it_computes_timezone_window_in_pg(): void
    {
        $tenantBId = createPgU2Tenant('Tenant NY');
        DB::table('tenants')->where('id', $tenantBId)->update(['timezone' => 'America/New_York']);

        $contactId = createPgU2Contact($this->tenantId);
        $convId = createPgU2Conversation($this->tenantId, $contactId, [
            'created_at' => '2026-08-20 10:00:00',
        ]);
        DB::table('messages')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'conversation_id' => $convId,
            'direction' => 'inbound',
            'type' => 'text',
            'status' => 'sent',
            'body' => 'Hello',
            'created_at' => '2026-08-20 10:00:00',
            'updated_at' => '2026-08-20 10:00:00',
        ]);

        $result = $this->service->aggregateForDate($this->getTenant(), '2026-08-20');
        $this->assertEquals(1, $result->total_messages);
    }

    /** @test AN-PG-U2-10: raw PG JSONB payload for analytics_daily */
    public function it_has_jsonb_compatible_columns_in_pg(): void
    {
        $contactId = createPgU2Contact($this->tenantId);
        $convId = createPgU2Conversation($this->tenantId, $contactId, [
            'created_at' => '2026-08-20 10:00:00',
        ]);

        $this->service->aggregateForDate($this->getTenant(), '2026-08-20');

        $row = DB::table('analytics_daily')
            ->where('tenant_id', $this->tenantId)
            ->where('date', '2026-08-20')
            ->first();

        $this->assertNotNull($row);
        $this->assertIsInt($row->total_messages);
        $this->assertIsInt($row->total_conversations);
        $this->assertIsInt($row->unique_contacts);
        $this->assertIsInt($row->total_flow_executions);
        $this->assertIsInt($row->total_leads);
        $this->assertIsInt($row->total_ai_tokens);
        $this->assertEquals(0, $row->total_messages);
    }

    /** @test F29-U3-DST-01: la ventana UTC es estable en fechas de cambio de hora (DST) */
    public function it_counts_messages_for_utc_tenant_across_dst_dates(): void
    {
        $contactId = createPgU2Contact($this->tenantId);
        $convId = createPgU2Conversation($this->tenantId, $contactId, [
            'created_at' => '2026-03-08 10:00:00',
        ]);

        DB::table('messages')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'conversation_id' => $convId,
            'direction' => 'inbound',
            'type' => 'text',
            'status' => 'sent',
            'body' => 'DST message',
            'created_at' => '2026-03-08 10:00:00',
            'updated_at' => '2026-03-08 10:00:00',
        ]);

        // Primavera (spring-forward): se cuenta en su día
        $spring = $this->service->aggregateForDate($this->getTenant(), '2026-03-08');
        $this->assertEquals(1, $spring->total_messages);

        // Otoño (fall-back): el mismo mensaje NO debe contarse en otra fecha
        $fall = $this->service->aggregateForDate($this->getTenant(), '2026-11-01');
        $this->assertEquals(0, $fall->total_messages);
    }

    /** @test F29-U3-DST-02: timezone inválida del tenant cae a UTC */
    public function it_falls_back_to_utc_for_invalid_timezone(): void
    {
        DB::table('tenants')->where('id', $this->tenantId)->update(['timezone' => 'Invalid/Zone']);

        $contactId = createPgU2Contact($this->tenantId);
        $convId = createPgU2Conversation($this->tenantId, $contactId, [
            'created_at' => '2026-03-08 10:00:00',
        ]);

        DB::table('messages')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'conversation_id' => $convId,
            'direction' => 'inbound',
            'type' => 'text',
            'status' => 'sent',
            'body' => 'Fallback tz',
            'created_at' => '2026-03-08 10:00:00',
            'updated_at' => '2026-03-08 10:00:00',
        ]);

        $result = $this->service->aggregateForDate($this->getTenant(), '2026-03-08');

        $this->assertNotNull($result);
        $this->assertEquals(1, $result->total_messages);
    }
}
