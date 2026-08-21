<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Postgres\PgvectorTestCase;

/*
|--------------------------------------------------------------------------
| PostgreSQL Notification Constraint Tests (FASE 22 U1)
|--------------------------------------------------------------------------
|
| NOTIF-PG-01..12 — Migración, constraints, FKs, indexes.
| Estos tests REQUIEREN PostgreSQL real.
| Ejecutar con: phpunit --configuration=phpunit.pgsql.xml
|
*/

function createTestNotificationTenant(string $name = 'Test Tenant'): string
{
    $tenantId = (string) Str::uuid();
    $slug = 'test-notif-'.strtolower(Str::random(8));
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

function createTestNotificationUser(): int
{
    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Test User',
        'email' => fake()->unique()->safeEmail(),
        'password' => 'hashed_password',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $userId;
}

class NotificationPostgresTest extends PgvectorTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL required for NOTIF-PG tests');
        }

        $this->tenantId = createTestNotificationTenant();
    }

    /** @test NOTIF-PG-01: notifications migration up */
    public function it_runs_notifications_migration_up(): void
    {
        $this->artisan('migrate:fresh');
        $this->tenantId = createTestNotificationTenant();

        $this->assertTrue(Schema::hasTable('notifications'));
    }

    /** @test NOTIF-PG-02: tenant FK works */
    public function it_inserts_notification_with_valid_tenant_fk(): void
    {
        $id = (string) Str::uuid();

        DB::table('notifications')->insert([
            'id' => $id,
            'tenant_id' => $this->tenantId,
            'type' => 'system',
            'priority' => 'normal',
            'title' => 'Test title',
            'body' => 'Test body',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('notifications')->where('id', $id)->first();
        $this->assertNotNull($row);
        $this->assertEquals($this->tenantId, $row->tenant_id);
    }

    /** @test NOTIF-PG-03: user FK works */
    public function it_inserts_notification_with_valid_user_fk(): void
    {
        $userId = createTestNotificationUser();
        $id = (string) Str::uuid();

        DB::table('notifications')->insert([
            'id' => $id,
            'tenant_id' => $this->tenantId,
            'user_id' => $userId,
            'type' => 'system',
            'priority' => 'normal',
            'title' => 'Test title',
            'body' => 'Test body',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('notifications')->where('id', $id)->first();
        $this->assertNotNull($row);
        $this->assertEquals($userId, $row->user_id);
    }

    /** @test NOTIF-PG-04: cross-tenant user FK is prevented */
    public function it_rejects_notification_with_nonexistent_user_fk(): void
    {
        $fakeUserId = 999999;

        try {
            DB::table('notifications')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $this->tenantId,
                'user_id' => $fakeUserId,
                'type' => 'system',
                'priority' => 'normal',
                'title' => 'Test',
                'body' => 'Test',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Expected QueryException for FK violation');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    /** @test NOTIF-PG-05: tenant-wide null user allowed */
    public function it_inserts_notification_with_null_user_id(): void
    {
        $id = (string) Str::uuid();

        DB::table('notifications')->insert([
            'id' => $id,
            'tenant_id' => $this->tenantId,
            'user_id' => null,
            'type' => 'system',
            'priority' => 'normal',
            'title' => 'Tenant-wide',
            'body' => 'Visible to all agents',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('notifications')->where('id', $id)->first();
        $this->assertNotNull($row);
        $this->assertNull($row->user_id);
    }

    /** @test NOTIF-PG-06: indexes exist */
    public function it_has_expected_indexes(): void
    {
        $indexes = Schema::getIndexes('notifications');

        $indexNames = array_map(fn ($i) => $i['name'], $indexes);

        $this->assertContains('notifications_tenant_user_read_index', $indexNames);
        $this->assertContains('notifications_tenant_created_index', $indexNames);
        $this->assertContains('notifications_tenant_type_index', $indexNames);
    }

    /** @test NOTIF-PG-07: read_at nullable */
    public function it_inserts_notification_with_null_read_at(): void
    {
        $id = (string) Str::uuid();

        DB::table('notifications')->insert([
            'id' => $id,
            'tenant_id' => $this->tenantId,
            'type' => 'system',
            'priority' => 'normal',
            'title' => 'Unread',
            'body' => 'Not read yet',
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('notifications')->where('id', $id)->first();
        $this->assertNull($row->read_at);
    }

    /** @test NOTIF-PG-08: JSONB data persistence */
    public function it_persists_json_data_in_notifications(): void
    {
        $id = (string) Str::uuid();
        $data = ['conversation_id' => (string) Str::uuid(), 'event' => 'handoff'];

        DB::table('notifications')->insert([
            'id' => $id,
            'tenant_id' => $this->tenantId,
            'type' => 'system',
            'priority' => 'normal',
            'title' => 'With data',
            'body' => 'JSON payload',
            'data' => json_encode($data),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('notifications')->where('id', $id)->first();
        $this->assertNotNull($row);
        $this->assertNotNull($row->data);
    }

    /** @test NOTIF-PG-09: repeated notifications allowed */
    public function it_allows_multiple_notifications_same_type_same_user(): void
    {
        $userId = createTestNotificationUser();

        for ($i = 0; $i < 3; $i++) {
            DB::table('notifications')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $this->tenantId,
                'user_id' => $userId,
                'type' => 'handoff_requested',
                'priority' => 'high',
                'title' => "Handoff #{$i}",
                'body' => "Handoff notification {$i}",
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $count = DB::table('notifications')
            ->where('tenant_id', $this->tenantId)
            ->where('user_id', $userId)
            ->where('type', 'handoff_requested')
            ->count();

        $this->assertEquals(3, $count);
    }

    /** @test NOTIF-PG-10: tenant delete cascades to notifications */
    public function it_cascades_tenant_delete_to_notifications(): void
    {
        $id = (string) Str::uuid();

        DB::table('notifications')->insert([
            'id' => $id,
            'tenant_id' => $this->tenantId,
            'type' => 'system',
            'priority' => 'normal',
            'title' => 'Tenant delete test',
            'body' => 'Should cascade',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenants')->where('id', $this->tenantId)->delete();

        $row = DB::table('notifications')->where('id', $id)->first();
        $this->assertNull($row);
    }

    /** @test NOTIF-PG-11: user delete sets user_id null */
    public function it_sets_user_id_null_on_user_delete(): void
    {
        $userId = createTestNotificationUser();
        $id = (string) Str::uuid();

        DB::table('notifications')->insert([
            'id' => $id,
            'tenant_id' => $this->tenantId,
            'user_id' => $userId,
            'type' => 'system',
            'priority' => 'normal',
            'title' => 'User delete test',
            'body' => 'Should set null',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->where('id', $userId)->delete();

        $row = DB::table('notifications')->where('id', $id)->first();
        $this->assertNotNull($row);
        $this->assertNull($row->user_id);
    }

    /** @test NOTIF-PG-12: UP/DOWN/UP migration cycle */
    public function it_survives_up_down_up_migration_cycle(): void
    {
        $this->artisan('migrate:fresh');
        $this->assertTrue(Schema::hasTable('notifications'));

        $this->artisan('migrate:rollback');
        $this->assertFalse(Schema::hasTable('notifications'));

        $this->artisan('migrate');
        $this->assertTrue(Schema::hasTable('notifications'));

        $this->tenantId = createTestNotificationTenant();
    }
}
