<?php

declare(strict_types=1);

namespace App\Domain\Contacts\Models;

use App\Domain\Tenants\Models\Tenant;
use App\Domain\Tenants\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Etiqueta de contacto (FASE 7, preparado para FASE 20).
 *
 * No hay API/UI en esta fase: la tabla y el modelo existen para que el CRM
 * pueda etiquetar contactos sin migraciones retroactivas.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 */
final class Tag extends Model
{
    use BelongsToTenant;
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
