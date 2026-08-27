<?php

declare(strict_types=1);

use App\Domain\Leads\Models\Lead;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Postgres\PgvectorTestCase;

/*
|--------------------------------------------------------------------------
| FASE 29 U3 — Leads dedup race (PostgreSQL)
|--------------------------------------------------------------------------
|
| F29-U3-LEAD-* — La deduplicación de leads es de aplicación (LeadService),
| NO hay UNIQUE en la tabla. Se reproduce el patrón check-then-insert
| (checkDuplicate → SELECT exists → INSERT) con dos conexiones PG para
| demostrar que bajo concurrencia real duplicados son posibles.
|
| PHP pthreads no está disponible; se intercalan operaciones raw
| (mismo patrón que NotificationConcurrencyTest) para probar la semántica.
|
| Ejecutar: docker compose exec app vendor/bin/pest --configuration=phpunit.pgsql.xml
|
*/

function f29u3LeadTenant(string $name = 'Test Tenant'): string
{
    $tenantId = (string) Str::uuid();
    DB::table('tenants')->insert([
        'id' => $tenantId,
        'name' => $name,
        'slug' => 'test-lead-conc-'.strtolower(Str::random(8)),
        'status' => 'active',
        'timezone' => 'UTC',
        'locale' => 'en',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $tenantId;
}

/**
 * Replica exacta del predicado de LeadService::checkDuplicate()
 * (sinTenantScope + tenant + deleted_at null + phone).
 */
function f29u3LeadExistsOn(string $conn, string $tenantId, string $phone): bool
{
    return DB::connection($conn)
        ->table('leads')
        ->where('tenant_id', $tenantId)
        ->whereNull('deleted_at')
        ->where('phone', $phone)
        ->exists();
}

function f29u3InsertLeadOn(string $conn, string $tenantId, string $phone, string $name): void
{
    DB::connection($conn)->table('leads')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => $tenantId,
        'name' => $name,
        'phone' => $phone,
        'status' => 'new',
        'source' => 'manual',
        'notes' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Lead confirmation: withoutTenantScope helper.
 */
function f29u3CountLeads(string $tenantId, string $phone): int
{
    return DB::table('leads')
        ->where('tenant_id', $tenantId)
        ->where('phone', $phone)
        ->count();
}

class LeadDedupConcurrencyTest extends PgvectorTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL required for F29-U3-LEAD-* tests');
        }

        $this->tenantId = f29u3LeadTenant();
    }

    /** @test F29-U3-LEAD-01: dedup predica actualmente impide un duplicado SEQUENCIAL */
    public function it_blocks_seqential_duplicate_via_check_then_insert(): void
    {
        f29u3InsertLeadOn('pgsql', $this->tenantId, '+529931000001', 'Primer lead');

        // Segundo intento: el SELECT existe encuentra el commit previo
        $exists = f29u3LeadExistsOn('pgsql', $this->tenantId, '+529931000001');

        expect($exists)->toBeTrue()
            ->and(f29u3CountLeads($this->tenantId, '+529931000001'))->toBe(1);
    }

    /** @test F29-U3-LEAD-02: check-then-insert concurrente puede crear duplicados (sin UNIQUE en DB) */
    public function it_allows_duplicate_rows_under_concurrent_check_then_insert(): void
    {
        $phone = '+529931000002';

        // Dos conexiones independientes (dos "requests" concurrentes)
        $conn1 = DB::connection('pgsql');
        $conn2 = DB::connection('pgsql');

        $conn1->beginTransaction();
        $conn2->beginTransaction();

        // T1: ambos checkDuplicate() ven el mismo estado (sin rows commit)
        $saw1 = f29u3LeadExistsOn('pgsql', $this->tenantId, $phone);
        $saw2 = f29u3LeadExistsOn('pgsql', $this->tenantId, $phone);

        expect($saw1)->toBeFalse()
            ->and($saw2)->toBeFalse();

        // T2: ambos INSERT sin conflicto (no hay UNIQUE en phone)
        f29u3InsertLeadOn('pgsql', $this->tenantId, $phone, 'Lead A');
        f29u3InsertLeadOn('pgsql', $this->tenantId, $phone, 'Lead B');

        $conn1->commit();
        $conn2->commit();

        // Ambas rows persistieron → duplicado bajo concurrencia
        expect(f29u3CountLeads($this->tenantId, $phone))->toBe(2);
    }

    /** @test F29-U3-LEAD-03: el mismo phone en tenants distintos es válido bajo concurrencia */
    public function it_allows_same_phone_across_tenants_concurrently(): void
    {
        $tenantB = f29u3LeadTenant('Tenant B');
        $phone = '+529931000003';

        $conn1 = DB::connection('pgsql');
        $conn2 = DB::connection('pgsql');

        $saw1 = f29u3LeadExistsOn('pgsql', $this->tenantId, $phone);
        $saw2 = f29u3LeadExistsOn('pgsql', $tenantB, $phone);

        expect($saw1)->toBeFalse()->and($saw2)->toBeFalse();

        f29u3InsertLeadOn('pgsql', $this->tenantId, $phone, 'Tenant A lead');
        f29u3InsertLeadOn('pgsql', $tenantB, $phone, 'Tenant B lead');

        expect(f29u3CountLeads($this->tenantId, $phone))->toBe(1)
            ->and(f29u3CountLeads($tenantB, $phone))->toBe(1);
    }

    /** @test F29-U3-LEAD-04: la tabla leads NO expone UNIQUE sobre phone (root cause) */
    public function it_has_no_unique_constraint_on_phone(): void
    {
        $index = DB::selectOne(
            'SELECT indexdef FROM pg_indexes
             WHERE tablename = :t AND indexname = :i',
            ['t' => 'leads', 'i' => 'leads_tenant_phone_index'],
        );

        expect($index)->not->toBeNull()
            ->and($index->indexdef)->not->toContain('UNIQUE');

        // Verificación directa del catálogo de constraints (sin UNIQUE)
        $constraints = DB::select('SELECT conname, contype FROM pg_constraint WHERE conrelid = \'leads\'::regclass AND contype = \'u\'');

        expect($constraints)->toBeEmpty();
    }
}
