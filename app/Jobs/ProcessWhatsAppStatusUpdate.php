<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Messages\Services\MessageService;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\WhatsApp\Enums\WebhookEventStatus;
use App\Domain\WhatsApp\Enums\WebhookEventType;
use App\Domain\WhatsApp\Models\WebhookEvent;
use App\Jobs\Concerns\TenantAwareJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Procesa un status update (sent/delivered/read/failed) de Meta (FASE 6 + FASE 9).
 *
 * FASE 6: ingesta + acuse idempotente. FASE 9 (ADR-032): actualiza el mensaje
 * persistido por `provider_message_id` (nunca crea mensajes); `failed` además
 * pasa la conversación a `pending`. Si el mensaje no existe, el evento se marca
 * `processed` igualmente (no-op) para no reintentar en bucle.
 */
final class ProcessWhatsAppStatusUpdate implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use SerializesModels;
    use TenantAwareJob;

    public int $tries = 3;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 15, 60];
    }

    public function __construct(public readonly string $webhookEventId) {}

    protected function executeInTenantContext(): void
    {
        $event = WebhookEvent::query()->find($this->webhookEventId);

        if ($event === null) {
            return;
        }

        if ($event->status !== WebhookEventStatus::Enqueued || $event->event_type !== WebhookEventType::Status) {
            return;
        }

        if ($event->tenant_id !== $this->tenantId) {
            return;
        }

        $tenant = Tenant::query()->find($event->tenant_id);

        if ($tenant === null) {
            $event->markFailed('tenant_not_found');

            return;
        }

        $data = $event->payload['data'] ?? null;

        if (is_array($data)) {
            app(MessageService::class)->handleStatusUpdate($tenant, $data);
        }

        $event->markProcessed();
    }
}
