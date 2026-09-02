<?php

declare(strict_types=1);

use App\Domain\WhatsApp\Enums\WebhookEventStatus;
use App\Domain\WhatsApp\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function retention_event(string $id, WebhookEventStatus $status, ?Carbon $processedAt): WebhookEvent
{
    return WebhookEvent::query()->create([
        'provider_event_id' => $id,
        'status' => $status,
        'event_type' => 'message',
        'payload' => ['phone_number_id' => 'phone-retention', 'type' => 'message', 'data' => ['id' => $id]],
        'processed_at' => $processedAt,
    ]);
}

test('U2-RETENTION-01: pruning elimina terminales vencidos y conserva replayables', function (): void {
    config([
        'whatsapp.webhook_retention_days' => 7,
        'whatsapp.webhook_failed_retention_days' => 30,
        'whatsapp.webhook_prune_batch' => 100,
    ]);

    $oldProcessed = retention_event('retention-old-processed', WebhookEventStatus::Processed, now()->subDays(8));
    $oldFailed = retention_event('retention-old-failed', WebhookEventStatus::Failed, now()->subDays(31));
    $recentProcessed = retention_event('retention-recent-processed', WebhookEventStatus::Processed, now()->subDays(1));
    $received = retention_event('retention-received', WebhookEventStatus::Received, null);

    $this->artisan('whatsapp:prune-webhook-events')->assertExitCode(0);

    expect(WebhookEvent::query()->whereKey($oldProcessed->id)->exists())->toBeFalse()
        ->and(WebhookEvent::query()->whereKey($oldFailed->id)->exists())->toBeFalse()
        ->and(WebhookEvent::query()->whereKey($recentProcessed->id)->exists())->toBeTrue()
        ->and(WebhookEvent::query()->whereKey($received->id)->exists())->toBeTrue();
});
