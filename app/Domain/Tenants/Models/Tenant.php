<?php

declare(strict_types=1);

namespace App\Domain\Tenants\Models;

use App\Domain\Business\Models\BusinessProfile;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Tenants\Enums\TenantStatus;
use App\Domain\Users\Models\TenantUser;
use App\Domain\Users\Models\User;
use App\Domain\WhatsApp\Models\WhatsAppAccount;
use App\Domain\WhatsApp\Models\WhatsAppPhoneNumber;
use Database\Factories\Domain\Tenants\Models\TenantFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Entidad raíz del multi-tenancy.
 *
 * NO es un modelo tenant (no lleva scope `tenant_id`): es el contenedor de los
 * demás. Todas las tablas del dominio tenant apuntan aquí.
 *
 * @property-read TenantStatus $status
 * @property-read Pivot|null $pivot
 */
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'name',
        'slug',
        'status',
        'plan_id',
        'timezone',
        'locale',
    ];

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
        ];
    }

    /**
     * Usuarios miembros del tenant (pivot `tenant_users`, rol por tenant).
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_users')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * @return HasMany<TenantUser, $this>
     */
    public function tenantUsers(): HasMany
    {
        return $this->hasMany(TenantUser::class);
    }

    /**
     * Usuarios cuyo tenant activo es este.
     *
     * @return HasMany<User, $this>
     */
    public function currentUsers(): HasMany
    {
        return $this->hasMany(User::class, 'current_tenant_id');
    }

    public function isActive(): bool
    {
        return $this->status === TenantStatus::Active;
    }

    /**
     * Perfil de negocio 1:1 (FASE 5). El perfil se crea bajo demanda desde
     * `BusinessProfileService` en la primera lectura.
     *
     * @return HasOne<BusinessProfile, $this>
     */
    public function businessProfile(): HasOne
    {
        return $this->hasOne(BusinessProfile::class);
    }

    /**
     * Cuenta de WhatsApp Business conectada (FASE 6, 1:1).
     *
     * @return HasOne<WhatsAppAccount, $this>
     */
    public function whatsappAccount(): HasOne
    {
        return $this->hasOne(WhatsAppAccount::class);
    }

    /**
     * Números de WhatsApp conectados (FASE 6).
     *
     * @return HasMany<WhatsAppPhoneNumber, $this>
     */
    public function whatsappPhoneNumbers(): HasMany
    {
        return $this->hasMany(WhatsAppPhoneNumber::class);
    }

    /**
     * Contactos del CRM básico (FASE 7, ADR-030).
     *
     * @return HasMany<Contact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }
}
