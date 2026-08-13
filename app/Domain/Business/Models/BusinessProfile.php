<?php

declare(strict_types=1);

namespace App\Domain\Business\Models;

use App\Domain\Tenants\Models\Tenant;
use App\Domain\Tenants\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Perfil de negocio de un tenant (FASE 5).
 *
 * Relación 1:1 con Tenant. `tenant_id` es gestionado por `BelongsToTenant`
 * (TenantContext) y NO es asignable masivamente: el frontend nunca lo envía.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $name
 * @property string|null $description
 * @property string|null $category
 * @property string|null $address
 * @property string|null $website
 * @property string|null $email
 * @property string|null $phone
 * @property array<int, array<string, mixed>>|null $working_hours
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class BusinessProfile extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'name',
        'description',
        'category',
        'address',
        'website',
        'email',
        'phone',
        'working_hours',
    ];

    protected $casts = [
        'working_hours' => 'array',
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
