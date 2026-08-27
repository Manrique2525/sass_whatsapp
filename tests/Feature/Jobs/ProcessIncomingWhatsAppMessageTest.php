<?php

declare(strict_types=1);

use App\Domain\Tenants\Models\Tenant;
use App\Domain\WhatsApp\Enums\WebhookEventStatus;
use App\Domain\WhatsApp\Enums\WebhookEventType;
use App\Domain\WhatsApp\Models\WebhookEvent;
use App\Jobs\ProcessIncomingWhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| FASE 29 U4 — ProcessIncomingWhatsAppMessage guard branches
|--------------------------------------------------------------------------
|
| These tests exercise the idempotency/guards and, critically, the
| multi-tenant isolation guard of the inbound webhook job WITHOUT going
| through the full E2E webhook (already covered by WhatsAppWebhookTest and
| F26-U3-IN-02). They prove the job never acts on an event that is not
| strictly 'enqueued message for THIS tenant'.
|
*/

function u4_inbound_event(Tenant $tenant, array $overrides = []): WebhookEvent
{
    return WebhookEvent::query()->create(array_merge([
        'provider_event_id' => 'u4-in-'.uniqid('', true),
        'tenant_id' => $tenant->id,
        'status' => WebhookEventStatus::Enqueued,
        'event_type' => WebhookEventType::Message,
        'payload' => [
            'data' => [
                'id' => 'wamid-u4-'.uniqid('', true),
                'from' => '15550000001',
                'timestamp' => '1725000000',
                'type' => 'text',
                'text' => ['body' => 'Hola'],
            ],
        ],
    ], $overrides));
}

test('U4-IN-GUARD-01: evento inexistente es no-op (sin excepción)', function (): void {
    $job = new ProcessIncomingWhatsAppMessage((string) Str::uuid());
    $job->forTenant((string) Str::uuid())->handle();

    expect(true)->toBeTrue();
});

test('U4-IN-GUARD-02: evento ya procesado no se procesa de nuevo (idempotencia)', function (): void {
    $tenant = Tenant::factory()->create();
    $event = u4_inbound_event($tenant, ['status' => WebhookEventStatus::Processed]);

    (new ProcessIncomingWhatsAppMessage($event->id))->forTenant($tenant->id)->handle();

    $event->refresh();

    expect($event->status)->toBe(WebhookEventStatus::Processed);
});

test('U4-IN-GUARD-03: evento de tipo status no es consumido por el job de message', function (): void {
    $tenant = Tenant::factory()->create();
    $event = u4_inbound_event($tenant, [
        'status' => WebhookEventStatus::Enqueued,
        'event_type' => WebhookEventType::Status,
    ]);

    (new ProcessIncomingWhatsAppMessage($event->id))->forTenant($tenant->id)->handle();

    $event->refresh();

    expect($event->status)->toBe(WebhookEventStatus::Enqueued)
        ->and($event->processed_at)->toBeNull();
});

test('U4-IN-ISO-01: CRITICO — el job jamás actúa sobre un evento de otro tenant', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    // Evento perteneciente a TENANT A.
    $event = u4_inbound_event($tenantA);

    // El job se ejecuta en el contexto de TENANT B (aislamiento multi-tenant).
    (new ProcessIncomingWhatsAppMessage($event->id))->forTenant($tenantB->id)->handle();

    $event->refresh();

    // Debe permanecer enqueued: ni marcado processed ni failed.
    expect($event->status)->toBe(WebhookEventStatus::Enqueued)
        ->and($event->processed_at)->toBeNull()
        ->and($event->error_code)->toBeNull();
});

test('U4-IN-ERR-01: payload sin data se marca failed con invalid_payload', function (): void {
    $tenant = Tenant::factory()->create();
    $event = u4_inbound_event($tenant, ['payload' => ['foo' => 'bar']]);

    (new ProcessIncomingWhatsAppMessage($event->id))->forTenant($tenant->id)->handle();

    $event->refresh();

    expect($event->status)->toBe(WebhookEventStatus::Failed)
        ->and($event->error_code)->toBe('invalid_payload');
});
