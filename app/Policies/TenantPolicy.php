<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;

/**
 * Autorización sobre tenants. Nada se decide con datos del frontend: todas las
 * comprobaciones verifican pertenencia real en `tenant_users`.
 */
final class TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * El servicio de aplicación exige además que sea el tenant activo (404 si
     * no lo es). La policy solo valida pertenencia para no revelar nada.
     */
    public function view(User $user, Tenant $tenant): bool
    {
        return $user->belongsToTenant($tenant);
    }

    public function update(User $user, Tenant $tenant): bool
    {
        return $user->belongsToTenant($tenant);
    }

    /**
     * Cambiar el tenant activo requiere pertenencia y tenant activo.
     */
    public function switch(User $user, Tenant $tenant): bool
    {
        return $user->belongsToTenant($tenant) && $tenant->isActive();
    }
}
