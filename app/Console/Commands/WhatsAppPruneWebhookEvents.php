<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\WhatsApp\Enums\WebhookEventStatus;
use App\Domain\WhatsApp\Models\WebhookEvent;
use Illuminate\Console\Command;

/** Prunes only terminal webhook events; received/enqueued events remain replayable. */
final class WhatsAppPruneWebhookEvents extends Command
{
    protected $signature = 'whatsapp:prune-webhook-events';

    protected $description = 'Elimina webhook events terminales fuera de retención.';

    public function handle(): int
    {
        $batch = max(1, (int) config('whatsapp.webhook_prune_batch', 100));
        $processedCutoff = now()->subDays(max(1, (int) config('whatsapp.webhook_retention_days', 7)));
        $failedCutoff = now()->subDays(max(1, (int) config('whatsapp.webhook_failed_retention_days', 30)));

        $processed = WebhookEvent::query()
            ->where('status', WebhookEventStatus::Processed->value)
            ->where('processed_at', '<', $processedCutoff)
            ->limit($batch)
            ->get();

        $failed = WebhookEvent::query()
            ->where('status', WebhookEventStatus::Failed->value)
            ->where('processed_at', '<', $failedCutoff)
            ->limit(max(0, $batch - $processed->count()))
            ->get();

        $events = $processed->merge($failed);

        foreach ($events as $event) {
            $event->delete();
        }

        $this->info(sprintf('Eliminados %d webhook events terminales.', $events->count()));

        return self::SUCCESS;
    }
}
