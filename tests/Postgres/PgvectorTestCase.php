<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Domain\Billing\Contracts\UsageGuardInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeUsageGuard;

/**
 * Base test case for pgvector/FASE 17 PostgreSQL tests.
 *
 * Extends PostgresConcurrencyTestCase (which is globally registered in
 * Pest.php via pest()->extend()) to avoid Pest test case conflicts.
 *
 * Uses RefreshDatabase (not DatabaseTruncation) because these tests
 * need clean migrations per test to verify DDL semantics.
 *
 * Safety: inherits all safety guards from PostgresConcurrencyTestCase
 * (HANDOFF_U2_PG_TEST, DB name validation, Redis config validation).
 */
abstract class PgvectorTestCase extends PostgresConcurrencyTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') === 'pgsql') {
            app()->instance(UsageGuardInterface::class, new FakeUsageGuard);
        }
    }

    protected function beforeTruncatingDatabase(): void
    {
        parent::beforeTruncatingDatabase();

        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL required for pgvector tests');
        }
    }
}
