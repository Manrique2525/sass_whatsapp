<?php

declare(strict_types=1);

namespace App\Domain\Flows\Models;

use App\Domain\Tenants\Models\Tenant;
use App\Domain\Tenants\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Chatbot del tenant (FASE 11).
 *
 * Contenedor de flujos. La activación ocurre a nivel de flujo
 * (`flows.status`: draft/published/inactive); el chatbot solo agrupa. Soft
 * delete: eliminar el chatbot oculta sus flujos.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $description
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 */
final class Chatbot extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'description',
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return HasMany<Flow, $this>
     */
    public function flows(): HasMany
    {
        return $this->hasMany(Flow::class);
    }
}
