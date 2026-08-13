<?php

declare(strict_types=1);

namespace App\Domain\Tenants\Scopes;

use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Scope global de aislamiento por tenant.
 *
 * - Con contexto activo: filtra por `tenant_id = TenantContext::id()`.
 * - Sin contexto: devuelve vacío (`whereRaw('1 = 0')`) — fallo seguro que jamás
 *   expone datos. Los admin que necesiten lecturas cross-tenant lo hacen SOLO a
 *   través de `scopeWithoutTenantScope()` en servicios de aplicación autorizados.
 */
final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = TenantContext::id();

        if ($tenantId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('tenant_id'), $tenantId);
    }
}
