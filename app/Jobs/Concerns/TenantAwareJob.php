<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Infrastructure\Tenancy\TenantContext;

/**
 * Marca un Job como tenant-aware.
 *
 * El job transporta `tenant_id` explícitamente (nunca confía en el contexto del
 * proceso que lo encola). Al ejecutarse establece su propio TenantContext y lo
 * libera en `finally`, de modo que jamás contamina a otros jobs del worker ni
 * deja contexto colgando ante una excepción.
 *
 * Uso:
 *   final class MiJob implements ShouldQueue
 *   {
 *       use TenantAwareJob;
 *
 *       protected function executeInTenantContext(): void { ... }
 *   }
 */
trait TenantAwareJob
{
    public string $tenantId;

    /**
     * Asigna explícitamente el tenant del job (encadenable).
     */
    public function forTenant(string $tenantId): static
    {
        $this->tenantId = $tenantId;

        return $this;
    }

    public function handle(): void
    {
        try {
            TenantContext::setId($this->tenantId);
            $this->executeInTenantContext();
        } finally {
            TenantContext::clear();
        }
    }

    abstract protected function executeInTenantContext(): void;
}
