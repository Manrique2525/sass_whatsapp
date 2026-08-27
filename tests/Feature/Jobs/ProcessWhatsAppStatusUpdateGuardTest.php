<?php

declare(strict_types=1);

use App\Domain\Tenants\Models\Tenant;
use App\Domain\WhatsApp\Enums\WebhookEventStatus;
use App\Domain\WhatsApp\Enums\WebhookEventType;
use App\Domain\WhatsApp\Models\WebhookEvent;
use App\Jobs\ProcessWhatsAppStatusUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| FASE 29 U4 — ProcessWhatsAppStatusUpdate guard branches
|--------------------------------------------------------------------------
|
| The failed() lifecycle and happy path are already covered by F26-U3-STAT-*
| and WhatsAppWebhookTest. These tests cover the *guard* branches: idempotency
| and the critical multi-tenant isolation guard.
|
*/

function u4_status_event(Tenant $tenant, array $overrides = []): WebhookEvent
{
    return WebhookEvent::query()->create(array_merge([
        'provider_event_id' => 'u4-stat-'.uniqid('', true),
        'tenant_id' => $tenant->id,
        'status' => WebhookEventStatus::Enqueued,
        'event_type' => WebhookEventType::Status,
        'payload' => [
            'data' => [
                'id' => 'wamid-u4-status',
                'status' => 'delivered',
                'timestamp' => '1725000000',
            ],
        ],
    ], $overrides));
}

test('U4-STAT-GUARD-01: evento inexistente es no-op (sin excepción)', function (): void {
    $job = new ProcessWhatsAppStatusUpdate((string) Str::uuid());
    $job->forTenant((string) Str::uuid())->handle();

    expect(true)->toBeTrue();
});

test('U4-STAT-GUARD-02: evento ya procesado no se procesa de nuevo (idempotencia)', function (): void {
    $tenant = Tenant::factory()->create();
    $event = u4_status_event($tenant, ['status' => WebhookEventStatus::Processed]);

    (new ProcessWhatsAppStatusUpdate($event->id))->forTenant($tenant->id)->handle();

    $event->refresh();

    expect($event->status)->toBe(WebhookEventStatus::Processed);
});

test('U4-STAT-ISO-01: CRITICO — el job jamás actúa sobre un evento de otro tenant', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $event = u4_status_event($tenantA);

    (new ProcessWhatsAppStatusUpdate($event->id))->forTenant($tenantB->id)->handle();

    $event->refresh();

    expect($event->status)->toBe(WebhookEventStatus::Enqueued)
        ->and($event->processed_at)->toBeNull()
        ->and($event->error_code)->toBeNull();
});

test('U4-STAT-ARR-01: payload sin data se marca processed sin tocar mensajes (no-op)', function (): void {
    $tenant = Tenant::factory()->create();
    $event = u4_status_event($tenant, ['payload' => ['foo' => 'bar']]);

    (new ProcessWhatsAppStatusUpdate($event->id))->forTenant($tenant->id)->handle();

    $event->refresh();

    // El job no actualiza ningún mensaje (no hay data), pero confirmar el acuse
    // idempotente: el evento se marca processed para no reintentar en bucle.
    expect($event->status)->toBe(WebhookEventStatus::Processed);
});
