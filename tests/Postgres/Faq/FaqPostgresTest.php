<?php

declare(strict_types=1);

use App\Domain\Faq\Models\Faq;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Postgres\PgvectorTestCase;

/*
|--------------------------------------------------------------------------
| PostgreSQL FAQ Constraint Tests (FASE 18 U1)
|--------------------------------------------------------------------------
|
| FAQ-PG-01..10 — Migración, constraints, partial unique index.
| Estos tests REQUIEREN PostgreSQL real.
| Ejecutar con: php artisan test --group=FAQ-PG
|
*/

function createTestFaqTenant(string $name = 'Test Tenant'): string
{
    $tenantId = (string) Str::uuid();
    $slug = 'test-faq-'.strtolower(Str::random(8));
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

class FaqPostgresTest extends PgvectorTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL required for FAQ-PG tests');
        }

        $this->tenantId = createTestFaqTenant();
    }

    /** @test FAQ-PG-01: migration up */
    public function it_runs_faqs_migration_up(): void
    {
        $this->artisan('migrate:fresh');

        $this->assertTrue(Schema::hasTable('faqs'));
    }

    /** @test FAQ-PG-02: columns are correct */
    public function it_has_correct_columns(): void
    {
        $this->artisan('migrate:fresh');

        $columns = Schema::getColumns('faqs');
        $columnNames = array_column($columns, 'name');

        $this->assertContains('id', $columnNames);
        $this->assertContains('tenant_id', $columnNames);
        $this->assertContains('question', $columnNames);
        $this->assertContains('normalized_question', $columnNames);
        $this->assertContains('answer', $columnNames);
        $this->assertContains('status', $columnNames);
        $this->assertContains('priority', $columnNames);
        $this->assertContains('deleted_at', $columnNames);
    }

    /** @test FAQ-PG-03: tenant FK works */
    public function it_inserts_with_valid_tenant_fk(): void
    {
        $this->artisan('migrate:fresh');

        $id = (string) Str::uuid();

        DB::table('faqs')->insert([
            'id' => $id,
            'tenant_id' => $this->tenantId,
            'question' => '¿Cuál es tu horario?',
            'normalized_question' => 'cuál es tu horario',
            'answer' => 'Lunes a viernes de 9 a 18.',
            'status' => 'active',
            'priority' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('faqs')->where('id', $id)->first();
        $this->assertNotNull($row);
        $this->assertEquals($this->tenantId, $row->tenant_id);
    }

    /** @test FAQ-PG-04: duplicate normalized_question same tenant → reject */
    public function it_rejects_duplicate_normalized_question_same_tenant(): void
    {
        $this->artisan('migrate:fresh');

        DB::table('faqs')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'question' => '¿Cuál es tu horario?',
            'normalized_question' => 'cuál es tu horario',
            'answer' => 'Lunes a viernes.',
            'status' => 'active',
            'priority' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('faqs')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'question' => '¿Cuál es tu horario?',
            'normalized_question' => 'cuál es tu horario',
            'answer' => 'Otra respuesta.',
            'status' => 'active',
            'priority' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @test FAQ-PG-05: same normalized_question different tenant → allowed */
    public function it_allows_same_normalized_question_different_tenant(): void
    {
        $this->artisan('migrate:fresh');

        $tenantB = createTestFaqTenant('Tenant B');

        DB::table('faqs')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'question' => '¿Cuál es tu horario?',
            'normalized_question' => 'cuál es tu horario',
            'answer' => 'Lunes a viernes.',
            'status' => 'active',
            'priority' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('faqs')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantB,
            'question' => '¿Cuál es tu horario?',
            'normalized_question' => 'cuál es tu horario',
            'answer' => 'Martes a sábado.',
            'status' => 'active',
            'priority' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $count = DB::table('faqs')->where('normalized_question', 'cuál es tu horario')->count();
        $this->assertEquals(2, $count);
    }

    /** @test FAQ-PG-06: soft delete allows recreating same normalized_question */
    public function it_allows_recreate_after_soft_delete(): void
    {
        $this->artisan('migrate:fresh');

        $id = (string) Str::uuid();

        DB::table('faqs')->insert([
            'id' => $id,
            'tenant_id' => $this->tenantId,
            'question' => '¿Cuál es tu horario?',
            'normalized_question' => 'cuál es tu horario',
            'answer' => 'Lunes a viernes.',
            'status' => 'active',
            'priority' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Soft delete
        DB::table('faqs')->where('id', $id)->update(['deleted_at' => now()]);

        // Recreate with same normalized_question
        DB::table('faqs')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'question' => '¿Cuál es tu horario?',
            'normalized_question' => 'cuál es tu horario',
            'answer' => 'Nueva respuesta.',
            'status' => 'active',
            'priority' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $count = DB::table('faqs')
            ->where('tenant_id', $this->tenantId)
            ->where('normalized_question', 'cuál es tu horario')
            ->count();

        $this->assertEquals(1, $count);
    }

    /** @test FAQ-PG-07: partial unique index exists */
    public function it_has_partial_unique_index(): void
    {
        $this->artisan('migrate:fresh');

        $indexes = DB::select("SELECT indexname FROM pg_indexes WHERE tablename = 'faqs' AND indexname = 'faqs_tenant_normalized_question_unique'");
        $this->assertNotEmpty($indexes);
    }

    /** @test FAQ-PG-08: partial index has predicate deleted_at IS NULL */
    public function it_has_partial_index_predicate(): void
    {
        $this->artisan('migrate:fresh');

        $index = DB::selectOne(
            "SELECT indexdef FROM pg_indexes WHERE tablename = 'faqs' AND indexname = 'faqs_tenant_normalized_question_unique'"
        );

        $this->assertNotNull($index);
        $this->assertStringContainsString('WHERE deleted_at IS NULL', $index->indexdef);
    }

    /** @test FAQ-PG-09: migration down */
    public function it_runs_migration_down(): void
    {
        $this->artisan('migrate:fresh');
        $this->artisan('migrate:rollback');

        $this->assertFalse(Schema::hasTable('faqs'));
    }

    /** @test FAQ-PG-10: up/down/up cycle */
    public function it_handles_up_down_up_cycle(): void
    {
        $this->artisan('migrate:fresh');
        $this->assertTrue(Schema::hasTable('faqs'));

        $this->artisan('migrate:rollback');
        $this->assertFalse(Schema::hasTable('faqs'));

        $this->artisan('migrate');
        $this->assertTrue(Schema::hasTable('faqs'));

        // Verify we can still insert after re-migration
        DB::table('faqs')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'question' => 'Test',
            'normalized_question' => 'test',
            'answer' => 'Answer',
            'status' => 'active',
            'priority' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertEquals(1, DB::table('faqs')->count());
    }
}
