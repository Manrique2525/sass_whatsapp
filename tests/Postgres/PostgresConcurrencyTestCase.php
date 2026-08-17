<?php

declare(strict_types=1);

namespace Tests\Postgres;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use RuntimeException;
use Tests\TestCase;

abstract class PostgresConcurrencyTestCase extends TestCase
{
    use DatabaseTruncation;

    protected function beforeTruncatingDatabase(): void
    {
        if (env('HANDOFF_U2_PG_TEST') !== '1'
            || config('database.default') !== 'pgsql'
            || config('database.connections.pgsql.host') !== 'postgres'
            || config('database.connections.pgsql.database') !== 'whatsapp_saas_handoff_u2_test') {
            throw new RuntimeException('Configuración PostgreSQL U2 insegura; se aborta antes de migrar o truncar.');
        }

        $database = $this->app->make('db')->connection()->selectOne('SELECT current_database() AS name');

        if ($database?->name !== 'whatsapp_saas_handoff_u2_test') {
            throw new RuntimeException('La conexión no apunta a la DB PostgreSQL aislada de U2.');
        }

        if (config('cache.default') !== 'redis'
            || config('database.redis.default.host') !== 'redis'
            || (string) config('database.redis.default.database') !== '14'
            || (string) config('database.redis.cache.database') !== '14') {
            throw new RuntimeException('Configuración Redis U2 insegura; no se ejecutarán tests de locks.');
        }
    }
}
