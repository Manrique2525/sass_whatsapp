<?php

declare(strict_types=1);

namespace App\Domain\Tenants\Traits;

use App\Domain\Tenants\Exceptions\TenantContextMissingException;
use App\Domain\Tenants\Scopes\TenantScope;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Marca un modelo como perteneciente a un tenant.
 *
 * - Aplica el scope global `TenantScope` (aislamiento automático en lecturas).
 * - Auto-rellena `tenant_id` al crear si hay TenantContext activo.
 * - Sin contexto, escribir lanza TenantContextMissingException (fallo seguro).
 *
 * Uso: `class Conversation extends Model { use BelongsToTenant; }`.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $tenantId = TenantContext::id();

            if ($tenantId === null) {
                throw new TenantContextMissingException(sprintf(
                    'No hay TenantContext activo al crear "%s". Toda escritura de un modelo tenant requiere contexto.',
                    $model::class,
                ));
            }

            $model->setAttribute('tenant_id', $tenantId);
        });
    }

    /**
     * Lecturas cross-tenant SOLO para servicios de aplicación autorizados.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithoutTenantScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class);
    }
}
