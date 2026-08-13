<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Jobs\Concerns\TenantAwareJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use RuntimeException;

/**
 * Job de prueba que lanza una excepción: verifica que el contexto se limpia en
 * `finally` incluso ante errores.
 */
final class FailingWidgetJob implements ShouldQueue
{
    use TenantAwareJob;

    protected function executeInTenantContext(): void
    {
        throw new RuntimeException('boom');
    }
}
