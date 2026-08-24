<?php

declare(strict_types=1);

namespace Tests\Postgres\Billing;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Postgres\PgvectorTestCase;

/*
|--------------------------------------------------------------------------
| PostgreSQL Billing U1 Tests (FASE 24 U1, ADR-092)
|--------------------------------------------------------------------------
|
| BILL-U1-PG-01..08 — Schema, constraints, FK, cascades for billing_customers.
| Estos tests REQUIEREN PostgreSQL real.
| Ejecutar con: vendor/bin/phpunit --group=BILL-U1-PG
|
*/

class BillingU1PostgresTest extends PgvectorTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL required for BILL-U1-PG tests');
        }

        $this->tenantId = $this->createTestTenant();
    }

    private function createTestTenant(string $name = 'Test Tenant'): string
    {
        $tenantId = (string) Str::uuid();
        $slug = 'test-billing-'.strtolower(Str::random(8));
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

    /** @test BILL-U1-PG-01: billing_customers table exists */
    public function it_has_billing_customers_table(): void
    {
        $this->assertTrue(Schema::hasTable('billing_customers'));
    }

    /** @test BILL-U1-PG-02: billing_customers has correct columns */
    public function it_has_correct_columns(): void
    {
        $columns = Schema::getColumnListing('billing_customers');

        $this->assertContains('id', $columns);
        $this->assertContains('tenant_id', $columns);
        $this->assertContains('provider', $columns);
        $this->assertContains('provider_customer_id', $columns);
        $this->assertContains('created_at', $columns);
        $this->assertContains('updated_at', $columns);
    }

    /** @test BILL-U1-PG-03: valid insert works */
    public function it_inserts_with_valid_data(): void
    {
        $id = (string) Str::uuid();

        DB::table('billing_customers')->insert([
            'id' => $id,
            'tenant_id' => $this->tenantId,
            'provider' => 'stripe',
            'provider_customer_id' => 'cus_'.Str::random(16),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('billing_customers')->where('id', $id)->first();
        $this->assertNotNull($row);
        $this->assertEquals($this->tenantId, $row->tenant_id);
        $this->assertEquals('stripe', $row->provider);
    }

    /** @test BILL-U1-PG-04: tenant FK works */
    public function it_rejects_invalid_tenant_fk(): void
    {
        $this->expectException(QueryException::class);

        DB::table('billing_customers')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => (string) Str::uuid(),
            'provider' => 'stripe',
            'provider_customer_id' => 'cus_fk_test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @test BILL-U1-PG-05: unique(tenant_id, provider) constraint */
    public function it_rejects_duplicate_tenant_provider(): void
    {
        DB::table('billing_customers')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'provider' => 'stripe',
            'provider_customer_id' => 'cus_unique_test_1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('billing_customers')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'provider' => 'stripe',
            'provider_customer_id' => 'cus_unique_test_2',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @test BILL-U1-PG-06: unique(provider, provider_customer_id) constraint */
    public function it_rejects_duplicate_provider_customer_id(): void
    {
        DB::table('billing_customers')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'provider' => 'stripe',
            'provider_customer_id' => 'cus_shared_id',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tenantB = $this->createTestTenant('Tenant B');

        $this->expectException(QueryException::class);

        DB::table('billing_customers')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantB,
            'provider' => 'stripe',
            'provider_customer_id' => 'cus_shared_id',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @test BILL-U1-PG-07: cascade on tenant delete */
    public function it_cascades_on_tenant_delete(): void
    {
        DB::table('billing_customers')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'provider' => 'stripe',
            'provider_customer_id' => 'cus_cascade_test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenants')->where('id', $this->tenantId)->delete();

        $count = DB::table('billing_customers')->where('tenant_id', $this->tenantId)->count();
        $this->assertEquals(0, $count);
    }

    /** @test BILL-U1-PG-08: plans table has stripe columns */
    public function it_has_stripe_price_columns_on_plans(): void
    {
        $columns = Schema::getColumnListing('plans');

        $this->assertContains('stripe_price_id_monthly', $columns);
        $this->assertContains('stripe_price_id_yearly', $columns);
    }
}
