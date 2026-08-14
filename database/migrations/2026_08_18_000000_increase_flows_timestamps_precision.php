<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lock optimista del editor de flujos (FASE 12, ADR-042): la comparación
 * `base_updated_at` vs `flows.updated_at` con precisión de segundos no detecta
 * dos escrituras en el mismo segundo. Con `timestamp(6)` (microsegundos,
 * Postgres) cada guardado deja una marca distinta y el conflicto se detecta
 * de forma fiable. En SQLite (tests) la precisión se mantiene en segundos y
 * los tests simulan la escritura concurrente ajustando `updated_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE flows ALTER COLUMN created_at TYPE timestamp(6) WITHOUT TIME ZONE');
        DB::statement('ALTER TABLE flows ALTER COLUMN updated_at TYPE timestamp(6) WITHOUT TIME ZONE');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE flows ALTER COLUMN created_at TYPE timestamp(0) WITHOUT TIME ZONE');
        DB::statement('ALTER TABLE flows ALTER COLUMN updated_at TYPE timestamp(0) WITHOUT TIME ZONE');
    }
};
