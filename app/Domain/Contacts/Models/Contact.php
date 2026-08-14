<?php

declare(strict_types=1);

namespace App\Domain\Contacts\Models;

use App\Domain\Tenants\Models\Tenant;
use App\Domain\Tenants\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Contacto del CRM básico (FASE 7, ADR-030).
 *
 * `phone` SIEMPRE se guarda en E.164 canónico con `+` inicial (normalizado por
 * `ContactService::normalizePhone`); es único por tenant entre contactos
 * activos (índice único parcial, ver migración). `tenant_id` lo gestiona
 * `BelongsToTenant` y nunca se acepta del frontend.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $phone
 * @property string $name
 * @property string|null $email
 * @property string|null $avatar_url
 * @property array<string, mixed>|null $metadata
 * @property string|null $provider_contact_id
 * @property Carbon|null $last_interaction_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 */
final class Contact extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'phone',
        'email',
        'avatar_url',
        'metadata',
        'provider_contact_id',
        'last_interaction_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'last_interaction_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Etiquetas del contacto (FASE 20). La tabla `contact_tag` existe desde
     * FASE 7 aunque no haya API/UI de tags todavía.
     *
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'contact_tag')
            ->withTimestamps();
    }
}
