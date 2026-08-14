<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Messages\Services\MessageService;
use App\Domain\Messages\Exceptions\UnsupportedMessageTypeException;
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
 * Procesa un mensaje entrante recibido por el webhook (FASE 6 + FASE 9).
 *
 * FASE 6: ingesta (recibir → verificar → dedupe → resolver tenant → encolar) y
 * acuse idempotente. FASE 9 (ADR-032): desde aquí se persisten contact/
 * conversation/message (dedupe por `provider_message_id`) y se actualiza la
 * conversación, antes de marcar el evento `processed`.
 *
 * - Tipo de Meta no soportado → `UnsupportedMessageTypeException` (permanente):
 *   el evento se marca `failed` y NO se reintenta (el webhook ya respondió 200).
 * - Cualquier otra excepción se relanza: la cola reintenta (tries + backoff) y,
 *   tras el último intento, el job cae en `failed_jobs` sin marcar processed.
 */
final class ProcessIncomingWhatsAppMessage implements ShouldQueue
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

        if ($event->status !== WebhookEventStatus::Enqueued || $event->event_type !== WebhookEventType::Message) {
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

        if (! is_array($data)) {
            $event->markFailed('invalid_payload');

            return;
        }

        try {
            app(MessageService::class)->handleInboundMessage($tenant, $data);
        } catch (UnsupportedMessageTypeException) {
            $event->markFailed('unsupported_message_type');

            return;
        }

        $event->markProcessed();
    }
}
