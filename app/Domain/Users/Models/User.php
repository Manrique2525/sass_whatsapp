<?php

declare(strict_types=1);

namespace App\Domain\Users\Models;

use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\TenantMembershipStatus;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Notifications\ResetPasswordNotification;
use Database\Factories\Domain\Users\Models\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * Usuario de la plataforma.
 *
 * Multi-tenant: pertenece a cero o más tenants a través de `tenant_users`
 * (relación many-to-many con rol por tenant). El tenant activo se guarda en
 * `current_tenant_id`; la lógica de tenant activo/switch se implementa en FASE 3.
 */
class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'current_tenant_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Relaciones usuario <-> tenant (pivot `tenant_users`, rol por tenant).
     *
     * @return HasMany<TenantUser, $this>
     */
    public function tenantUsers(): HasMany
    {
        return $this->hasMany(TenantUser::class);
    }

    /**
     * Tenants a los que el usuario pertenece (rol por tenant).
     *
     * @return BelongsToMany<Tenant, $this>
     */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_users')
            ->withPivot('role', 'status')
            ->withTimestamps();
    }

    /**
     * Tenant activo del usuario (nullable).
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function currentTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'current_tenant_id');
    }

    /**
     * ¿El usuario es miembro ACTIVO del tenant? (validación SIEMPRE vía
     * `tenant_users`; los `invited`/`disabled` no cuentan como miembros).
     */
    public function belongsToTenant(Tenant $tenant): bool
    {
        return $this->tenantUsers()
            ->where('tenant_id', $tenant->id)
            ->where('status', TenantMembershipStatus::Active)
            ->exists();
    }

    /**
     * Variante por id para evitar cargar el modelo (canales de broadcast).
     */
    public function belongsToTenantById(string $tenantId): bool
    {
        return $this->tenantUsers()
            ->where('tenant_id', $tenantId)
            ->where('status', TenantMembershipStatus::Active)
            ->exists();
    }

    /**
     * ¿El tenant dado es el activo Y el usuario es miembro? Nunca confiar solo
     * en `current_tenant_id`: se valida la pertenencia contra `tenant_users`.
     */
    public function isCurrentTenant(Tenant $tenant): bool
    {
        return $this->current_tenant_id === $tenant->id && $this->belongsToTenant($tenant);
    }

    /**
     * Rol del usuario en un tenant (membresía activa) o null si no pertenece.
     */
    public function roleForTenant(string $tenantId): ?UserRole
    {
        $pivot = $this->tenantUsers()
            ->where('tenant_id', $tenantId)
            ->where('status', TenantMembershipStatus::Active)
            ->first();

        return $pivot?->role;
    }

    /**
     * Rol global de plataforma (fuera de cualquier tenant). Se evalúa contra el
     * pivot `model_has_roles` con el team sentinel GLOBAL (NUNCA `hasRole`, que
     * es team-scoped y no ve roles asignados con otro tenant_id) (ADR-025/026).
     */
    public function isSuperAdmin(): bool
    {
        return $this->hasGlobalRole(UserRole::SuperAdmin->value);
    }

    /**
     * ¿El usuario tiene el rol global de plataforma indicado?
     */
    public function hasGlobalRole(string $role): bool
    {
        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $this->id)
            ->where('model_has_roles.model_type', static::class)
            ->where('model_has_roles.tenant_id', UserRole::GLOBAL_TEAM_ID)
            ->where('roles.name', $role)
            ->exists();
    }

    /**
     * Enviar la notificación de restablecimiento de contraseña.
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token)
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
