<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\WhatsApp\Enums\WebhookEventStatus;
use App\Domain\WhatsApp\Enums\WebhookEventType;
use App\Domain\WhatsApp\Models\WebhookEvent;
use App\Jobs\Concerns\TenantAwareJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Procesa un mensaje entrante recibido por el webhook (FASE 6, ADR-029).
 *
 * El job transporta `tenant_id` explícito (TenantAwareJob) y es idempotente:
 * solo marca `processed` un evento en estado `enqueued` del tenant correcto.
 *
 * TODO FASE 9 (Mensajes): desde aquí se persisten contact/conversation/message
 * (dedupe por `provider_message_id`) y se dispara el motor de flujos. La FASE 6
 * implementa la ingesta (recibir → verificar → dedupe → resolver → encolar) y
 * el acuse, sin persistir el mensaje todavía.
 */
final class ProcessIncomingWhatsAppMessage implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use SerializesModels;
    use TenantAwareJob;

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

        $event->markProcessed();
    }
}
