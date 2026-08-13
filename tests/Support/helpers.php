<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Crea la tabla de `ScopedWidget` si no existe (por test, dentro de la
 * transacción de RefreshDatabase; se recrea al inicio de cada test).
 */
function create_scoped_widgets_table(): void
{
    if (Schema::hasTable('scoped_widgets')) {
        return;
    }

    Schema::create('scoped_widgets', function (Blueprint $table): void {
        $table->id();
        $table->string('tenant_id');
        $table->string('name');
        $table->timestamps();
    });
}

/**
 * Inserta un widget directamente (sin pasar por el hook de `BelongsToTenant`)
 * simulando un registro creado por el tenant indicado.
 */
function insert_scoped_widget(string $tenantId, string $name): void
{
    DB::table('scoped_widgets')->insert([
        'tenant_id' => $tenantId,
        'name' => $name,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}
