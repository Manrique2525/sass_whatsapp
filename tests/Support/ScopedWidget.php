<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Tenants\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Modelo de prueba con `BelongsToTenant` para verificar el aislamiento. No
 * existe tabla real en producto: se crea bajo demanda en cada test.
 */
final class ScopedWidget extends Model
{
    use BelongsToTenant;

    protected $table = 'scoped_widgets';

    protected $fillable = [
        'tenant_id',
        'name',
    ];
}
