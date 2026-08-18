<?php

declare(strict_types=1);

namespace Tests\Postgres;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Base test case for pgvector/FASE 17 PostgreSQL tests.
 *
 * Uses RefreshDatabase (not DatabaseTruncation) because these tests
 * don't need multi-process concurrency infrastructure.
 *
 * Safety: skips if DB_CONNECTION is not pgsql.
 */
abstract class PgvectorTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL required for pgvector tests');
        }
    }
}
