<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Postgres\PgvectorTestCase;

/*
|--------------------------------------------------------------------------
| PostgreSQL Analytics Constraint Tests (FASE 21 U1)
|--------------------------------------------------------------------------
|
| AN-PG-01..12 — Migración, constraints, FKs, UNIQUE, indexes.
| Estos tests REQUIEREN PostgreSQL real.
| Ejecutar con: phpunit --configuration=phpunit.pgsql.xml
|
*/

function createTestAnalyticsTenant(string $name = 'Test Tenant'): string
{
    $tenantId = (string) Str::uuid();
    $slug = 'test-analytics-'.strtolower(Str::random(8));
    DB::table('tenants')->insert([
        'id' => $tenantId,
        'name' => $name,
        'slug' => $slug,
        'status' => 'active',
        'timezone' => 'UTC',
        'locale' => 'en',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $tenantId;
}

function createTestAnalyticsConversation(string $tenantId): string
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

    $conversationId = (string) Str::uuid();
    DB::table('conversations')->insert([
        'id' => $conversationId,
        'tenant_id' => $tenantId,
        'contact_id' => $contactId,
        'status' => 'open',
        'auto_assigned' => false,
        'bot_paused' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $conversationId;
}

class AnalyticsPostgresTest extends PgvectorTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL required for AN-PG tests');
        }

        $this->tenantId = createTestAnalyticsTenant();
    }

    /** @test AN-PG-01: analytics_daily migration up */
    public function it_runs_analytics_daily_migration_up(): void
    {
        $this->artisan('migrate:fresh');
        $this->tenantId = createTestAnalyticsTenant();

        $this->assertTrue(Schema::hasTable('analytics_daily'));
    }

    /** @test AN-PG-02: conversation_metrics migration up */
    public function it_runs_conversation_metrics_migration_up(): void
    {
        $this->artisan('migrate:fresh');
        $this->tenantId = createTestAnalyticsTenant();

        $this->assertTrue(Schema::hasTable('conversation_metrics'));
    }

    /** @test AN-PG-03: FK analytics_daily → tenants works */
    public function it_inserts_analytics_daily_with_valid_tenant_fk(): void
    {
        $id = (string) Str::uuid();

        DB::table('analytics_daily')->insert([
            'id' => $id,
            'tenant_id' => $this->tenantId,
            'date' => '2026-01-15',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('analytics_daily')->where('id', $id)->first();
        $this->assertNotNull($row);
        $this->assertEquals($this->tenantId, $row->tenant_id);
    }

    /** @test AN-PG-04: FK conversation_metrics → tenants works */
    public function it_inserts_conversation_metrics_with_valid_tenant_fk(): void
    {
        $conversationId = createTestAnalyticsConversation($this->tenantId);
        $id = (string) Str::uuid();

        DB::table('conversation_metrics')->insert([
            'id' => $id,
            'tenant_id' => $this->tenantId,
            'conversation_id' => $conversationId,
            'message_count' => 5,
            'bot_message_count' => 3,
            'agent_message_count' => 2,
            'handoff_requested' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('conversation_metrics')->where('id', $id)->first();
        $this->assertNotNull($row);
        $this->assertEquals($this->tenantId, $row->tenant_id);
    }

    /** @test AN-PG-05: FK conversation_metrics → conversations (composite) works */
    public function it_inserts_conversation_metrics_with_valid_composite_fk(): void
    {
        $conversationId = createTestAnalyticsConversation($this->tenantId);

        DB::table('conversation_metrics')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'conversation_id' => $conversationId,
            'message_count' => 1,
            'bot_message_count' => 1,
            'agent_message_count' => 0,
            'handoff_requested' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $count = DB::table('conversation_metrics')
            ->where('tenant_id', $this->tenantId)
            ->where('conversation_id', $conversationId)
            ->count();

        $this->assertEquals(1, $count);
    }

    /** @test AN-PG-06: UNIQUE tenant/date — duplicate date rejected */
    public function it_rejects_duplicate_tenant_date(): void
    {
        $this->expectException(QueryException::class);

        DB::table('analytics_daily')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'date' => '2026-01-15',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('analytics_daily')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'date' => '2026-01-15',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @test AN-PG-07: same date across different tenants allowed */
    public function it_allows_same_date_across_tenants(): void
    {
        $tenantB = createTestAnalyticsTenant('Tenant B');

        DB::table('analytics_daily')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'date' => '2026-01-15',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('analytics_daily')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantB,
            'date' => '2026-01-15',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $count = DB::table('analytics_daily')
            ->where('date', '2026-01-15')
            ->count();

        $this->assertEquals(2, $count);
    }

    /** @test AN-PG-08: UNIQUE tenant/conversation — duplicate rejected */
    public function it_rejects_duplicate_tenant_conversation(): void
    {
        $this->expectException(QueryException::class);

        $conversationId = createTestAnalyticsConversation($this->tenantId);

        DB::table('conversation_metrics')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'conversation_id' => $conversationId,
            'message_count' => 1,
            'bot_message_count' => 1,
            'agent_message_count' => 0,
            'handoff_requested' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('conversation_metrics')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'conversation_id' => $conversationId,
            'message_count' => 1,
            'bot_message_count' => 1,
            'agent_message_count' => 0,
            'handoff_requested' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @test AN-PG-09: cross-tenant conversation reference blocked */
    public function it_blocks_cross_tenant_conversation_reference(): void
    {
        $this->expectException(QueryException::class);

        $conversationId = createTestAnalyticsConversation($this->tenantId);
        $tenantB = createTestAnalyticsTenant('Tenant B');

        DB::table('conversation_metrics')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantB,
            'conversation_id' => $conversationId,
            'message_count' => 1,
            'bot_message_count' => 1,
            'agent_message_count' => 0,
            'handoff_requested' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @test AN-PG-10: indexes exist */
    public function it_has_correct_indexes(): void
    {
        $dailyIndex = DB::selectOne(
            "SELECT indexdef FROM pg_indexes WHERE tablename = 'analytics_daily' AND indexname = 'analytics_daily_tenant_date_index'"
        );
        $this->assertNotNull($dailyIndex, 'analytics_daily_tenant_date_index must exist');
        $this->assertStringContainsString('tenant_id', $dailyIndex->indexdef);
        $this->assertStringContainsString('date', $dailyIndex->indexdef);

        $dailyUnique = DB::selectOne(
            "SELECT indexdef FROM pg_indexes WHERE tablename = 'analytics_daily' AND indexname = 'analytics_daily_tenant_date_unique'"
        );
        $this->assertNotNull($dailyUnique, 'analytics_daily_tenant_date_unique must exist');
        $this->assertStringContainsString('UNIQUE', $dailyUnique->indexdef);

        $metricsUnique = DB::selectOne(
            "SELECT indexdef FROM pg_indexes WHERE tablename = 'conversation_metrics' AND indexname = 'conversation_metrics_tenant_conversation_unique'"
        );
        $this->assertNotNull($metricsUnique, 'conversation_metrics_tenant_conversation_unique must exist');
        $this->assertStringContainsString('UNIQUE', $metricsUnique->indexdef);

        $metricsIndex = DB::selectOne(
            "SELECT indexdef FROM pg_indexes WHERE tablename = 'conversation_metrics' AND indexname = 'conversation_metrics_tenant_created_index'"
        );
        $this->assertNotNull($metricsIndex, 'conversation_metrics_tenant_created_index must exist');
    }

    /** @test AN-PG-11: defaults of counters are correct */
    public function it_has_correct_defaults(): void
    {
        $id = (string) Str::uuid();

        DB::table('analytics_daily')->insert([
            'id' => $id,
            'tenant_id' => $this->tenantId,
            'date' => '2026-01-15',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('analytics_daily')->where('id', $id)->first();
        $this->assertEquals(0, $row->total_messages);
        $this->assertEquals(0, $row->messages_inbound);
        $this->assertEquals(0, $row->messages_outbound);
        $this->assertEquals(0, $row->messages_delivered);
        $this->assertEquals(0, $row->messages_read);
        $this->assertEquals(0, $row->messages_failed);
        $this->assertEquals(0, $row->total_conversations);
        $this->assertEquals(0, $row->conversations_open);
        $this->assertEquals(0, $row->conversations_resolved);
        $this->assertEquals(0, $row->conversations_archived);
        $this->assertEquals(0, $row->conversations_handoff_requested);
        $this->assertEquals(0, $row->conversations_bot_paused);
        $this->assertEquals(0, $row->unique_contacts);
        $this->assertNull($row->avg_response_time_seconds);
        $this->assertEquals(0, $row->total_flow_executions);
        $this->assertEquals(0, $row->flow_executions_completed);
        $this->assertEquals(0, $row->flow_executions_failed);
        $this->assertEquals(0, $row->total_leads);
        $this->assertEquals(0, $row->leads_new);
        $this->assertEquals(0, $row->leads_won);
        $this->assertEquals(0, $row->leads_lost);
        $this->assertEquals(0, $row->total_ai_tokens);
    }

    /** @test AN-PG-12: up/down/up cycle */
    public function it_handles_up_down_up_cycle(): void
    {
        $this->artisan('migrate:fresh');
        $this->tenantId = createTestAnalyticsTenant();
        $this->assertTrue(Schema::hasTable('analytics_daily'));
        $this->assertTrue(Schema::hasTable('conversation_metrics'));

        $this->artisan('migrate:rollback', ['--step' => 2]);
        $this->assertFalse(Schema::hasTable('conversation_metrics'));
        $this->assertFalse(Schema::hasTable('analytics_daily'));

        $this->artisan('migrate');
        $this->tenantId = createTestAnalyticsTenant();
        $this->assertTrue(Schema::hasTable('analytics_daily'));
        $this->assertTrue(Schema::hasTable('conversation_metrics'));

        DB::table('analytics_daily')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'date' => '2026-01-15',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertEquals(1, DB::table('analytics_daily')->count());
    }
}
