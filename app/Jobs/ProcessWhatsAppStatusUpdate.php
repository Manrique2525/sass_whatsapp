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
 * Procesa un status update (sent/delivered/read/failed) de Meta (FASE 6).
 *
 * Idempotente: solo marca `processed` un evento `enqueued` del tenant correcto.
 *
 * TODO FASE 9 (Mensajes): actualizar el mensaje persistido por
 * `provider_message_id` (delivered → read → failed). La FASE 6 implementa la
 * ingesta y el acuse sin tocar la tabla `messages`.
 */
final class ProcessWhatsAppStatusUpdate implements ShouldQueue
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

        if ($event->status !== WebhookEventStatus::Enqueued || $event->event_type !== WebhookEventType::Status) {
            return;
        }

        if ($event->tenant_id !== $this->tenantId) {
            return;
        }

        $event->markProcessed();
    }
}
