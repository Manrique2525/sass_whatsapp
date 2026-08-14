<?php

declare(strict_types=1);

namespace App\Infrastructure\Tenancy;

use App\Domain\Tenants\Models\Tenant;

/**
 * Contexto activo de tenant para el proceso en curso.
 *
 * Es un estado estático por proceso (no por request): lo fija el middleware
 * `tenant` (HTTP), los jobs tenant-aware (cola) y los servicios de aplicación
 * autorizados. SIEMPRE debe liberarse con `clear()` (en `finally`).
 *
 * - Sin contexto: los modelos con `BelongsToTenant` fallan de forma segura
 *   (lecturas devuelven vacío; escrituras lanzan TenantContextMissingException).
 * - Nunca se debe confiar en el contexto existente al encolar un job: los jobs
 *   transportan `tenant_id` y restablecen su propio contexto.
 */
final class TenantContext
{
    private static ?string $tenantId = null;

    private static ?Tenant $tenant = null;

    public static function set(Tenant $tenant): void
    {
        self::$tenant = $tenant;
        self::$tenantId = $tenant->id;
    }

    public static function setId(string $tenantId): void
    {
        self::$tenant = null;
        self::$tenantId = $tenantId;
    }

    public static function tenant(): ?Tenant
    {
        if (self::$tenant !== null) {
            return self::$tenant;
        }

        if (self::$tenantId !== null) {
            return Tenant::query()->find(self::$tenantId);
        }

        return null;
    }

    public static function id(): ?string
    {
        return self::$tenantId;
    }

    public static function bound(): bool
    {
        return self::$tenantId !== null;
    }

    public static function clear(): void
    {
        self::$tenant = null;
        self::$tenantId = null;
    }

    /**
     * Ejecuta un callback bajo un contexto de tenant sin pisar un contexto ya
     * activo.
     *
     * Los servicios de aplicación que crean modelos tenant (p. ej.
     * `createOutbound`) establecen y liberan el contexto localmente. Cuando son
     * invocados dentro de un contexto mayor (un job tenant-aware o el motor de
     * flujos), NUNCA deben limpiarlo: este helper solo setea/limpia si no había
     * contexto previo.
     */
    public static function withId(string $tenantId, callable $callback): mixed
    {
        $wasBound = self::bound();

        if (! $wasBound) {
            self::setId($tenantId);
        }

        try {
            return $callback();
        } finally {
            if (! $wasBound) {
                self::clear();
            }
        }
    }
}
