<?php

declare(strict_types=1);

namespace App\Application\Tenants\Services;

use App\Application\Audit\Services\AuditLogger;
use App\Domain\Tenants\Enums\TenantStatus;
use App\Domain\Tenants\Exceptions\TenantMembershipException;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Operaciones de lectura/actualización de tenants a las que un usuario tiene
 * acceso. Toda consulta parte del usuario autenticado y valida pertenencia;
 * jamás recibe `tenant_id` del frontend para decidir a quién pertenece.
 */
final class TenantService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @return Collection<int, Tenant>
     */
    public function listForUser(User $user): Collection
    {
        return $user->tenants()->orderBy('name')->get();
    }

    /**
     * @return Collection<int, Tenant>
     */
    public function availableForUser(User $user): Collection
    {
        return $user->tenants()->where('status', TenantStatus::Active)->orderBy('name')->get();
    }

    public function currentForUser(User $user): ?Tenant
    {
        $tenant = $user->currentTenant;

        if ($tenant === null || ! $user->belongsToTenant($tenant)) {
            return null;
        }

        return $tenant;
    }

    /**
     * Solo el tenant activo actual es visible/editable. Otro tenant (aunque el
     * usuario pertenezca) requiere hacer switch primero.
     */
    public function showForUser(User $user, Tenant $tenant): Tenant
    {
        if (! $user->isCurrentTenant($tenant)) {
            throw new TenantMembershipException('El tenant no es el activo del usuario.');
        }

        return $tenant;
    }

    /**
     * @param  array{name: string, timezone: string, locale: string}  $validated
     */
    public function update(User $user, Tenant $tenant, array $validated): Tenant
    {
        if (! $user->isCurrentTenant($tenant)) {
            throw new TenantMembershipException('El tenant no es el activo del usuario.');
        }

        $tenant->fill([
            'name' => $validated['name'],
            'timezone' => $validated['timezone'],
            'locale' => $validated['locale'],
        ])->save();

        $this->auditLogger->record(
            action: 'tenant.updated',
            data: ['tenant_id' => $tenant->id, 'tenant_slug' => $tenant->slug],
            subjectType: Tenant::class,
            subjectId: $tenant->id,
        );

        return $tenant->fresh();
    }
}
