<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Flows\Services\FlowEngine;
use App\Domain\Flows\Models\FlowExecution;
use App\Domain\Tenants\Models\Tenant;
use App\Jobs\Concerns\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Continúa una ejecución de flujo de forma programada (FASE 11, ADR-037).
 *
 * Modos:
 * - `delay`: un nodo `delay` terminó su espera; el motor avanza al siguiente
 *   nodo y sigue el ciclo.
 * - `retry`: un fallo transitorio (webhook) reintenta el nodo actual con
 *   backoff (máx 3 intentos) antes de marcar la ejecución `failed`.
 *
 * Idempotencia/concurrencia:
 * - `ShouldBeUnique` (por execution + modo) evita duplicados en cola.
 * - `TenantAwareJob` establece su propio TenantContext y lo limpia en
 *   `finally`; la ejecución se resuelve filtrando por `tenant_id`.
 * - El motor vuelve a validar el estado bajo el lock de conversación: si la
 *   ejecución ya terminó o el bot está pausado, no hace nada.
 */
final class ContinueFlowExecution implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TenantAwareJob;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public readonly string $executionId,
        public string $mode = 'delay',
    ) {}

    public function mode(string $mode): static
    {
        $this->mode = $mode;

        return $this;
    }

    public function uniqueId(): string
    {
        return "flow-execution:{$this->executionId}:{$this->mode}";
    }

    public function uniqueFor(): int
    {
        return 3600;
    }

    protected function executeInTenantContext(): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        $execution = FlowExecution::query()
            ->withoutTenantScope()
            ->where('tenant_id', $this->tenantId)
            ->whereKey($this->executionId)
            ->first();

        if ($execution === null) {
            return;
        }

        app(FlowEngine::class)->continueExecution($tenant, $execution, $this->mode);
    }
}
