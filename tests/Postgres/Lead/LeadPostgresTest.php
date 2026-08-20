<?php

declare(strict_types=1);

use App\Domain\Leads\Models\Lead;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Postgres\PgvectorTestCase;

/*
|--------------------------------------------------------------------------
| PostgreSQL Lead Constraint Tests (FASE 19 U1)
|--------------------------------------------------------------------------
|
| LEAD-PG-01..12 — Migración, constraints, partial indexes, CHECK.
| Estos tests REQUIEREN PostgreSQL real.
| Ejecutar con: php artisan test --group=LEAD-PG
|
*/

function createTestLeadTenant(string $name = 'Test Tenant'): string
{
    $tenantId = (string) Str::uuid();
    $slug = 'test-lead-'.strtolower(Str::random(8));
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

class LeadPostgresTest extends PgvectorTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL required for LEAD-PG tests');
        }

        $this->tenantId = createTestLeadTenant();
    }

    /** @test LEAD-PG-01: migration up */
    public function it_runs_leads_migration_up(): void
    {
        $this->artisan('migrate:fresh');

        $this->assertTrue(Schema::hasTable('leads'));
    }

    /** @test LEAD-PG-02: tenant FK works */
    public function it_inserts_with_valid_tenant_fk(): void
    {
        $this->artisan('migrate:fresh');

        $id = (string) Str::uuid();

        DB::table('leads')->insert([
            'id' => $id,
            'tenant_id' => $this->tenantId,
            'name' => 'Juan Pérez',
            'phone' => '+529931234567',
            'email' => 'juan@example.com',
            'status' => 'new',
            'source' => 'manual',
            'notes' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('leads')->where('id', $id)->first();
        $this->assertNotNull($row);
        $this->assertEquals($this->tenantId, $row->tenant_id);
    }

    /** @test LEAD-PG-03: status CHECK constraint valid values accepted */
    public function it_accepts_valid_status_values(): void
    {
        $this->artisan('migrate:fresh');

        $validStatuses = ['new', 'contacted', 'qualified', 'won', 'lost'];

        foreach ($validStatuses as $status) {
            DB::table('leads')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $this->tenantId,
                'name' => 'Lead '.$status,
                'status' => $status,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->assertEquals(5, DB::table('leads')->where('tenant_id', $this->tenantId)->count());
    }

    /** @test LEAD-PG-04: status CHECK constraint rejects invalid value */
    public function it_rejects_invalid_status_value(): void
    {
        $this->artisan('migrate:fresh');

        $this->expectException(QueryException::class);

        DB::table('leads')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'name' => 'Bad Status Lead',
            'status' => 'invalid_status',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @test LEAD-PG-05: name CHECK constraint rejects empty name */
    public function it_rejects_empty_name(): void
    {
        $this->artisan('migrate:fresh');

        $this->expectException(QueryException::class);

        DB::table('leads')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'name' => '',
            'status' => 'new',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @test LEAD-PG-06: name CHECK constraint rejects whitespace-only name */
    public function it_rejects_whitespace_only_name(): void
    {
        $this->artisan('migrate:fresh');

        $this->expectException(QueryException::class);

        DB::table('leads')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'name' => '   ',
            'status' => 'new',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @test LEAD-PG-07: same phone different leads allowed (NO UNIQUE) */
    public function it_allows_same_phone_different_leads(): void
    {
        $this->artisan('migrate:fresh');

        DB::table('leads')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'name' => 'Lead A',
            'phone' => '+529931234567',
            'status' => 'new',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('leads')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'name' => 'Lead B',
            'phone' => '+529931234567',
            'status' => 'new',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $count = DB::table('leads')
            ->where('tenant_id', $this->tenantId)
            ->where('phone', '+529931234567')
            ->count();

        $this->assertEquals(2, $count);
    }

    /** @test LEAD-PG-08: same email different leads allowed (NO UNIQUE) */
    public function it_allows_same_email_different_leads(): void
    {
        $this->artisan('migrate:fresh');

        DB::table('leads')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'name' => 'Lead A',
            'email' => 'shared@example.com',
            'status' => 'new',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('leads')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'name' => 'Lead B',
            'email' => 'shared@example.com',
            'status' => 'new',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $count = DB::table('leads')
            ->where('tenant_id', $this->tenantId)
            ->where('email', 'shared@example.com')
            ->count();

        $this->assertEquals(2, $count);
    }

    /** @test LEAD-PG-09: same data across tenants allowed */
    public function it_allows_same_data_across_tenants(): void
    {
        $this->artisan('migrate:fresh');

        $tenantB = createTestLeadTenant('Tenant B');

        DB::table('leads')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'name' => 'Lead A1',
            'phone' => '+529931234567',
            'email' => 'shared@example.com',
            'status' => 'new',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('leads')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantB,
            'name' => 'Lead B1',
            'phone' => '+529931234567',
            'email' => 'shared@example.com',
            'status' => 'new',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $count = DB::table('leads')->count();
        $this->assertEquals(2, $count);
    }

    /** @test LEAD-PG-10: invalid tenant rejected by FK */
    public function it_rejects_invalid_tenant_fk(): void
    {
        $this->artisan('migrate:fresh');

        $fakeTenantId = (string) Str::uuid();

        $this->expectException(QueryException::class);

        DB::table('leads')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $fakeTenantId,
            'name' => 'Orphan Lead',
            'status' => 'new',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @test LEAD-PG-11: partial indexes exist */
    public function it_has_partial_indexes_for_phone_and_email(): void
    {
        $this->artisan('migrate:fresh');

        $phoneIndex = DB::selectOne(
            "SELECT indexdef FROM pg_indexes WHERE tablename = 'leads' AND indexname = 'leads_tenant_phone_index'"
        );
        $this->assertNotNull($phoneIndex);
        $this->assertStringContainsString('WHERE phone IS NOT NULL AND deleted_at IS NULL', $phoneIndex->indexdef);

        $emailIndex = DB::selectOne(
            "SELECT indexdef FROM pg_indexes WHERE tablename = 'leads' AND indexname = 'leads_tenant_email_index'"
        );
        $this->assertNotNull($emailIndex);
        $this->assertStringContainsString('WHERE email IS NOT NULL AND deleted_at IS NULL', $emailIndex->indexdef);
    }

    /** @test LEAD-PG-12: up/down/up cycle */
    public function it_handles_up_down_up_cycle(): void
    {
        $this->artisan('migrate:fresh');
        $this->assertTrue(Schema::hasTable('leads'));

        $this->artisan('migrate:rollback');
        $this->assertFalse(Schema::hasTable('leads'));

        $this->artisan('migrate');
        $this->assertTrue(Schema::hasTable('leads'));

        DB::table('leads')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'name' => 'Test',
            'status' => 'new',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertEquals(1, DB::table('leads')->count());
    }
}
