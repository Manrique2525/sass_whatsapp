<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Jobs\Concerns\TenantAwareJob;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Job de prueba que escribe un ScopedWidget dentro del contexto que establece
 * la cola. Permite verificar el aislamiento en jobs sin depender de modelos
 * reales (aún no existen módulos de dominio tenant).
 */
final class WriteWidgetJob implements ShouldQueue
{
    use TenantAwareJob;

    public function __construct(private readonly string $name) {}

    protected function executeInTenantContext(): void
    {
        ScopedWidget::query()->create(['name' => $this->name]);
    }
}
