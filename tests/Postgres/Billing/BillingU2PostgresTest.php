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
| PostgreSQL Billing U2 Tests (FASE 24 U2, ADR-093)
|--------------------------------------------------------------------------
|
| BILL-U2-PG-01..04 — billing_customers constraints and billing schema for U2.
| Estos tests REQUIEREN PostgreSQL real.
| Ejecutar con: vendor/bin/phpunit --group=BILL-U2-PG
|
*/

class BillingU2PostgresTest extends PgvectorTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL required for BILL-U2-PG tests');
        }

        $this->tenantId = $this->createTestTenant();
    }

    private function createTestTenant(string $name = 'Test Tenant'): string
    {
        $tenantId = (string) Str::uuid();
        $slug = 'test-billing-u2-'.strtolower(Str::random(8));
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

    /** @test BILL-U2-PG-01: billing_customers has unique constraint on (tenant_id, provider) */
    public function it_enforces_unique_tenant_provider_constraint(): void
    {
        DB::table('billing_customers')->insert([
            'id' => Str::uuid(),
            'tenant_id' => $this->tenantId,
            'provider' => 'stripe',
            'provider_customer_id' => 'cus_first_123',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Second insert with same (tenant_id, provider) should fail
        $this->expectException(QueryException::class);
        DB::table('billing_customers')->insert([
            'id' => Str::uuid(),
            'tenant_id' => $this->tenantId,
            'provider' => 'stripe',
            'provider_customer_id' => 'cus_second_456',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @test BILL-U2-PG-02: billing_customers allows different providers for same tenant */
    public function it_allows_different_providers_for_same_tenant(): void
    {
        DB::table('billing_customers')->insert([
            'id' => Str::uuid(),
            'tenant_id' => $this->tenantId,
            'provider' => 'stripe',
            'provider_customer_id' => 'cus_stripe_123',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('billing_customers')->insert([
            'id' => Str::uuid(),
            'tenant_id' => $this->tenantId,
            'provider' => 'other',
            'provider_customer_id' => 'cus_other_456',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $count = DB::table('billing_customers')
            ->where('tenant_id', $this->tenantId)
            ->count();

        $this->assertEquals(2, $count);
    }

    /** @test BILL-U2-PG-03: billing_customers unique constraint on (provider, provider_customer_id) */
    public function it_enforces_unique_provider_customer_id(): void
    {
        DB::table('billing_customers')->insert([
            'id' => Str::uuid(),
            'tenant_id' => $this->tenantId,
            'provider' => 'stripe',
            'provider_customer_id' => 'cus_shared_123',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Different tenant but same provider_customer_id should fail
        $otherTenantId = $this->createTestTenant('Other Tenant');

        $this->expectException(QueryException::class);
        DB::table('billing_customers')->insert([
            'id' => Str::uuid(),
            'tenant_id' => $otherTenantId,
            'provider' => 'stripe',
            'provider_customer_id' => 'cus_shared_123',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @test BILL-U2-PG-04: stripe_price_id columns exist on plans table */
    public function it_has_stripe_price_id_columns_on_plans(): void
    {
        $this->assertTrue(Schema::hasColumn('plans', 'stripe_price_id_monthly'));
        $this->assertTrue(Schema::hasColumn('plans', 'stripe_price_id_yearly'));

        // Verify nullable
        DB::table('plans')->insert([
            'id' => Str::uuid(),
            'slug' => 'test-plan-'.Str::random(4),
            'name' => 'Test Plan',
            'description' => 'Test',
            'is_active' => true,
            'price_monthly' => 29.00,
            'price_yearly' => 290.00,
            'limits' => '{}',
            'features' => '{}',
            'sort_order' => 0,
            'stripe_price_id_monthly' => null,
            'stripe_price_id_yearly' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $plan = DB::table('plans')->whereNull('stripe_price_id_monthly')->first();
        $this->assertNotNull($plan);
    }
}
