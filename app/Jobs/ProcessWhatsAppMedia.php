<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Messages\Services\MediaStorageService;
use App\Domain\Messages\Models\MessageMedia;
use App\Domain\Tenants\Models\Tenant;
use App\Jobs\Concerns\TenantAwareJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Descarga segura y almacenamiento de un media entrante (FASE 31 U5, ADR-121).
 *
 * Se encola tras persistir el mensaje inbound con media; nunca se descarga
 * dentro de la request del webhook.
 *
 * - Tenant-aware y `ShouldBeUnique` por media id: un asset se procesa una vez.
 * - `MediaStorageService::process` implementa CAS `pending → processing` y marca
 *   estados terminales (`downloaded`/`failed`), por lo que el job es idempotente
 *   y los reintentos no duplican trabajo ni revisitan assets terminados.
 * - Reintento acotado por la cola; fallos permanentes quedan en `failed` (el
 *   job detecta terminal y no reintenta).
 */
final class ProcessWhatsAppMedia implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use SerializesModels;
    use TenantAwareJob;

    public int $timeout = 120;

    public int $tries = 10;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 15, 30, 60, 120, 300];
    }

    public function __construct(
        string $tenantId,
        public readonly string $messageMediaId,
    ) {
        $this->tenantId = $tenantId;
    }

    public function uniqueId(): string
    {
        return 'media:'.$this->messageMediaId;
    }

    public function uniqueFor(): int
    {
        return 300;
    }

    protected function executeInTenantContext(): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        $media = MessageMedia::query()
            ->withoutTenantScope()
            ->where('tenant_id', $this->tenantId)
            ->whereKey($this->messageMediaId)
            ->first();

        if ($media === null || $media->processing_status->isTerminal()) {
            return;
        }

        app(MediaStorageService::class)->process($media);
    }
}
