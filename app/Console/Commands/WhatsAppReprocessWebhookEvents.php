<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\WhatsApp\Services\WhatsAppWebhookService;
use App\Domain\WhatsApp\Enums\WebhookEventStatus;
use App\Domain\WhatsApp\Models\WebhookEvent;
use Illuminate\Console\Command;

/**
 * Sweeper del outbox de webhooks (FASE 9, ADR-032; whatsapp.md §4.3).
 *
 * Re-encola eventos que quedaron en `received` (proceso murió entre el insert
 * y el dispatch del job). Programado cada minuto desde routes/console.php.
 * Ventana de 5 minutos para no pelear con la ingesta en curso.
 */
final class WhatsAppReprocessWebhookEvents extends Command
{
    protected $signature = 'whatsapp:reprocess-webhook-events';

    protected $description = 'Re-encola webhook events en status received (outbox).';

    public function handle(WhatsAppWebhookService $service): int
    {
        $events = WebhookEvent::query()
            ->where('status', WebhookEventStatus::Received->value)
            ->where('created_at', '<', now()->subMinutes(5))
            ->orderBy('created_at')
            ->limit(100)
            ->get();

        foreach ($events as $event) {
            $service->reprocessEvent($event);
        }

        $this->info(sprintf('Re-procesados %d webhook events.', $events->count()));

        return self::SUCCESS;
    }
}
