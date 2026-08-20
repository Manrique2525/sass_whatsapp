<?php

declare(strict_types=1);

namespace App\Domain\Contacts\Models;

use App\Domain\Tenants\Models\Tenant;
use App\Domain\Tenants\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Etiqueta de contacto (FASE 7, FASE 20 U1).
 *
 * La tabla `tags` y `contact_tag` existen desde FASE 7. FASE 20 U1 agrega
 * TagService como writer centralizado y TagFactory para testing.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 */
final class Tag extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'name',
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
