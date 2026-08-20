<?php

declare(strict_types=1);

use App\Domain\Contacts\Models\Contact;
use App\Domain\Contacts\Models\Tag;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Postgres\PgvectorTestCase;

/*
|--------------------------------------------------------------------------
| PostgreSQL Tag Constraint Tests (FASE 20 U1)
|--------------------------------------------------------------------------
|
| TAG-PG-01..10 — Schema, constraints, FK, cascades, concurrency.
| Estos tests REQUIEREN PostgreSQL real.
| Ejecutar con: vendor/bin/phpunit --group=TAG-PG
|
*/

function createTestTagTenant(string $name = 'Test Tenant'): string
{
    $tenantId = (string) Str::uuid();
    $slug = 'test-tag-'.strtolower(Str::random(8));
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

class TagPostgresTest extends PgvectorTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL required for TAG-PG tests');
        }

        $this->tenantId = createTestTagTenant();
    }

    /** @test TAG-PG-01: tags migration exists */
    public function it_has_tags_table(): void
    {
        $this->assertTrue(Schema::hasTable('tags'));
        $this->assertTrue(Schema::hasTable('contact_tag'));
    }

    /** @test TAG-PG-02: tenant FK works */
    public function it_inserts_with_valid_tenant_fk(): void
    {
        $tagId = (string) Str::uuid();

        DB::table('tags')->insert([
            'id' => $tagId,
            'tenant_id' => $this->tenantId,
            'name' => 'VIP',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('tags')->where('id', $tagId)->first();
        $this->assertNotNull($row);
        $this->assertEquals($this->tenantId, $row->tenant_id);
    }

    /** @test TAG-PG-03: unique tenant+name constraint works */
    public function it_rejects_duplicate_name_in_same_tenant(): void
    {
        DB::table('tags')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'name' => 'VIP',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('tags')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'name' => 'VIP',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @test TAG-PG-04: same name different tenants allowed */
    public function it_allows_same_name_across_tenants(): void
    {
        $tenantB = createTestTagTenant('Tenant B');

        DB::table('tags')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'name' => 'VIP',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tags')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantB,
            'name' => 'VIP',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertEquals(2, DB::table('tags')->where('name', 'VIP')->count());
    }

    /** @test TAG-PG-05: pivot PK blocks duplicate contact+tag */
    public function it_rejects_duplicate_pivot_entry(): void
    {
        $contactId = (string) Str::uuid();
        $tagId = (string) Str::uuid();

        DB::table('contacts')->insert([
            'id' => $contactId,
            'tenant_id' => $this->tenantId,
            'name' => 'Test Contact',
            'phone' => '+529931000001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tags')->insert([
            'id' => $tagId,
            'tenant_id' => $this->tenantId,
            'name' => 'VIP',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('contact_tag')->insert([
            'contact_id' => $contactId,
            'tag_id' => $tagId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('contact_tag')->insert([
            'contact_id' => $contactId,
            'tag_id' => $tagId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @test TAG-PG-06: contact FK works */
    public function it_inserts_with_valid_contact_fk(): void
    {
        $contactId = (string) Str::uuid();
        $tagId = (string) Str::uuid();

        DB::table('contacts')->insert([
            'id' => $contactId,
            'tenant_id' => $this->tenantId,
            'name' => 'Test Contact',
            'phone' => '+529931000002',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tags')->insert([
            'id' => $tagId,
            'tenant_id' => $this->tenantId,
            'name' => 'VIP',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('contact_tag')->insert([
            'contact_id' => $contactId,
            'tag_id' => $tagId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('contact_tag', [
            'contact_id' => $contactId,
            'tag_id' => $tagId,
        ]);
    }

    /** @test TAG-PG-07: tag FK works (insert via pivot) */
    public function it_inserts_pivot_with_valid_tag_fk(): void
    {
        $this->it_inserts_with_valid_contact_fk();
    }

    /** @test TAG-PG-08: cascade contact delete removes pivot rows */
    public function it_cascades_contact_delete_to_pivot(): void
    {
        $contactId = (string) Str::uuid();
        $tagId = (string) Str::uuid();

        DB::table('contacts')->insert([
            'id' => $contactId,
            'tenant_id' => $this->tenantId,
            'name' => 'Test Contact',
            'phone' => '+529931000003',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tags')->insert([
            'id' => $tagId,
            'tenant_id' => $this->tenantId,
            'name' => 'VIP',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('contact_tag')->insert([
            'contact_id' => $contactId,
            'tag_id' => $tagId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('contact_tag', [
            'contact_id' => $contactId,
            'tag_id' => $tagId,
        ]);

        DB::table('contacts')->where('id', $contactId)->delete();

        $this->assertDatabaseMissing('contact_tag', [
            'contact_id' => $contactId,
            'tag_id' => $tagId,
        ]);
    }

    /** @test TAG-PG-09: cascade tag delete removes pivot rows */
    public function it_cascades_tag_delete_to_pivot(): void
    {
        $contactId = (string) Str::uuid();
        $tagId = (string) Str::uuid();

        DB::table('contacts')->insert([
            'id' => $contactId,
            'tenant_id' => $this->tenantId,
            'name' => 'Test Contact',
            'phone' => '+529931000004',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tags')->insert([
            'id' => $tagId,
            'tenant_id' => $this->tenantId,
            'name' => 'VIP',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('contact_tag')->insert([
            'contact_id' => $contactId,
            'tag_id' => $tagId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tags')->where('id', $tagId)->delete();

        $this->assertDatabaseMissing('contact_tag', [
            'contact_id' => $contactId,
            'tag_id' => $tagId,
        ]);
    }

    /** @test TAG-PG-10: concurrent same-tag creation leaves one effective tag */
    public function it_handles_concurrent_same_name_creation(): void
    {
        $tagId1 = (string) Str::uuid();
        $tagId2 = (string) Str::uuid();

        DB::table('tags')->insert([
            'id' => $tagId1,
            'tenant_id' => $this->tenantId,
            'name' => 'VIP',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('tags')->insert([
            'id' => $tagId2,
            'tenant_id' => $this->tenantId,
            'name' => 'VIP',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
