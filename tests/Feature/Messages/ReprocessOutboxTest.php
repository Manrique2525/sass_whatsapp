<?php

declare(strict_types=1);

use App\Domain\Billing\Contracts\CapacityGuardInterface;
use App\Domain\Messages\Models\Message;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\WhatsApp\Enums\WebhookEventStatus;
use App\Domain\WhatsApp\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Fakes\FakeCapacityGuard;

uses(RefreshDatabase::class);

beforeEach(fn () => app()->instance(CapacityGuardInterface::class, new FakeCapacityGuard));

/*
|--------------------------------------------------------------------------
| FASE 9 — OUTBOX: comando whatsapp:reprocess-webhook-events
|--------------------------------------------------------------------------
*/
function make_stale_received_event(Tenant $tenant, string $id, ?string $phoneNumberId = 'phone-1', ?int $minutesAgo = 10): WebhookEvent
{
    $ts = now()->subMinutes($minutesAgo);

    DB::table('webhook_events')->insert([
        'id' => (string) Str::uuid(),
        'provider_event_id' => $id,
        'tenant_id' => null,
        'payload' => json_encode([
            'phone_number_id' => $phoneNumberId,
            'type' => 'message',
            'data' => [
                'id' => $id,
                'from' => '15550000001',
                'timestamp' => '1725000000',
                'type' => 'text',
                'text' => ['body' => 'Hola outbox'],
            ],
        ], JSON_THROW_ON_ERROR),
        'status' => WebhookEventStatus::Received->value,
        'event_type' => 'message',
        'duplicate' => false,
        'error_code' => null,
        'processed_at' => null,
        'created_at' => $ts,
        'updated_at' => $ts,
    ]);

    return WebhookEvent::query()->where('provider_event_id', $id)->firstOrFail();
}

test('OUTBOX-1: un evento received mayor a 5 min se re-encola y procesa', function (): void {
    $tenant = Tenant::factory()->create();
    make_whatsapp_setup($tenant);
    make_stale_received_event($tenant, 'outbox-stale');

    $this->artisan('whatsapp:reprocess-webhook-events')
        ->expectsOutputToContain('Re-procesados 1 webhook events.')
        ->assertExitCode(0);

    $event = WebhookEvent::query()->where('provider_event_id', 'outbox-stale')->firstOrFail();

    expect($event->status)->toBe(WebhookEventStatus::Processed)
        ->and($event->tenant_id)->toBe($tenant->id);

    $this->assertDatabaseHas('messages', [
        'tenant_id' => $tenant->id,
        'provider_message_id' => 'outbox-stale',
        'body' => 'Hola outbox',
    ]);
});

test('OUTBOX-2: un evento reciente (menos de 5 min) no se toca', function (): void {
    $tenant = Tenant::factory()->create();
    make_whatsapp_setup($tenant);
    make_stale_received_event($tenant, 'outbox-fresh', minutesAgo: 1);

    $this->artisan('whatsapp:reprocess-webhook-events')->assertExitCode(0);

    $event = WebhookEvent::query()->where('provider_event_id', 'outbox-fresh')->firstOrFail();

    expect($event->status)->toBe(WebhookEventStatus::Received);

    expect(Message::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('OUTBOX-3: un evento con phone_id desconocido se marca failed sin reintentar en bucle', function (): void {
    $tenant = Tenant::factory()->create();
    make_stale_received_event($tenant, 'outbox-unknown-phone', phoneNumberId: 'phone-inexistente');

    $this->artisan('whatsapp:reprocess-webhook-events')->assertExitCode(0);

    $event = WebhookEvent::query()->where('provider_event_id', 'outbox-unknown-phone')->firstOrFail();

    expect($event->status)->toBe(WebhookEventStatus::Failed)
        ->and($event->error_code)->toBe('unknown_phone_number_id');
});

test('OUTBOX-4: un evento ya enqueued no se re-procesa', function (): void {
    $tenant = Tenant::factory()->create();
    $event = make_stale_received_event($tenant, 'outbox-enqueued');

    $event->forceFill([
        'status' => WebhookEventStatus::Enqueued->value,
        'tenant_id' => $tenant->id,
    ])->save();

    $this->artisan('whatsapp:reprocess-webhook-events')->assertExitCode(0);

    $event->refresh();

    expect($event->status)->toBe(WebhookEventStatus::Enqueued)
        ->and(Message::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(0);
});
