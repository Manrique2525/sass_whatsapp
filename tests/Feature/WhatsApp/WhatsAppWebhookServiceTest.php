<?php

declare(strict_types=1);

use App\Application\WhatsApp\Services\WhatsAppWebhookService;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\WhatsApp\Contracts\WhatsAppProviderInterface;
use App\Domain\WhatsApp\Enums\WebhookEventStatus;
use App\Domain\WhatsApp\Models\WebhookEvent;
use App\Jobs\ProcessIncomingWhatsAppMessage;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function u4_webhook_service(): WhatsAppWebhookService
{
    return new WhatsAppWebhookService(app(WhatsAppProviderInterface::class));
}

function u4_received_event(array $overrides = []): WebhookEvent
{
    return WebhookEvent::query()->create(array_merge([
        'provider_event_id' => 'u4-ws-'.uniqid('', true),
        'tenant_id' => null,
        'status' => WebhookEventStatus::Received,
        'event_type' => 'message',
        'payload' => [
            'phone_number_id' => 'phone-1',
            'data' => ['id' => 'wamid-ws', 'from' => '15550000001', 'type' => 'text'],
        ],
    ], $overrides));
}

function u4_ingest_malformed(array $entry): void
{
    config(['whatsapp.app_secret' => whatsapp_secret()]);

    $body = json_encode([
        'object' => 'whatsapp_business_account',
        'entry' => $entry,
    ], JSON_THROW_ON_ERROR);

    $request = Request::create('/api/webhooks/whatsapp', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => whatsapp_signature($body),
    ], $body);

    u4_webhook_service()->handle($request);
}

function u4_ingest_malformed_entry_scalar(mixed $entry): void
{
    config(['whatsapp.app_secret' => whatsapp_secret()]);

    $body = json_encode([
        'object' => 'whatsapp_business_account',
        'entry' => $entry,
    ], JSON_THROW_ON_ERROR);

    $request = Request::create('/api/webhooks/whatsapp', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => whatsapp_signature($body),
    ], $body);

    u4_webhook_service()->handle($request);
}

function u4_messages(mixed $value): array
{
    return [['field' => 'messages', 'value' => ['messages' => $value]]];
}

function u4_statuses(mixed $value): array
{
    return [['field' => 'messages', 'value' => ['statuses' => $value]]];
}

function u4_value(mixed $value): array
{
    return [['field' => 'messages', 'value' => $value]];
}

test('U4-WS-REPRO-01: reprocessEvent solo actúa sobre eventos en received', function (): void {
    $service = u4_webhook_service();

    // Evento ya enqueued: el sweeper NO debe re-encolar ni cambiar nada.
    $event = u4_received_event(['status' => WebhookEventStatus::Enqueued]);

    $service->reprocessEvent($event);

    $event->refresh();

    expect($event->status)->toBe(WebhookEventStatus::Enqueued);
});

test('U4-WS-REPRO-02: reprocessEvent con phone inexistente marca failed', function (): void {
    $service = u4_webhook_service();

    // phone_number_id = 'phone-inexistente' no existe → failed (sin reintento en bucle).
    $event = u4_received_event(['payload' => ['phone_number_id' => 'phone-inexistente']]);

    $service->reprocessEvent($event);

    $event->refresh();

    expect($event->status)->toBe(WebhookEventStatus::Failed)
        ->and($event->error_code)->toBe('unknown_phone_number_id');
});

test('U4-WS-REPRO-03: reprocessEvent re-encola correctamente un evento received perdido', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    make_whatsapp_setup($tenant); // crea phone_id = 'phone-1' vinculado al tenant

    $service = u4_webhook_service();
    $event = u4_received_event();

    $service->reprocessEvent($event);

    $event->refresh();

    Queue::assertPushed(ProcessIncomingWhatsAppMessage::class);

    expect($event->status)->toBe(WebhookEventStatus::Enqueued)
        ->and($event->tenant_id)->toBe($tenant->id);
});

test('U4-WS-INGEST-01: handle ignora silenciosamente payloads malformados pero JSON válido', function (): void {
    config(['whatsapp.app_secret' => whatsapp_secret()]);

    $service = u4_webhook_service();

    // Nota: NO se incluye la forma `['changes' => 'string']` aquí — esa forma
    // expone BUG-WEBHOOK-FOREACH (ver U4-WS-INGEST-BUG-01, reproducer skip).
    $body = json_encode([
        'object' => 'whatsapp_business_account',
        'entry' => [
            'no-es-array',
            ['changes' => [['field' => 'otro_campo', 'value' => ['messages' => [['id' => 'x']]]]]],
            ['changes' => [['field' => 'messages', 'value' => ['messages' => ['no-es-array-msg']]]]],
            ['changes' => [['field' => 'messages', 'value' => ['statuses' => ['no-es-array-status']]]]],
        ],
    ], JSON_THROW_ON_ERROR);

    $request = Request::create('/api/webhooks/whatsapp', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => whatsapp_signature($body),
    ], $body);

    // No debe lanzar excepción ni crear eventos.
    $service->handle($request);

    expect(WebhookEvent::query()->count())->toBe(0);
});

test('U2-WS-DISPATCH-01: un fallo de dispatch devuelve el evento a received para el sweeper', function (): void {
    $tenant = Tenant::factory()->create();
    make_whatsapp_setup($tenant);

    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue unavailable'));
    app()->instance(Dispatcher::class, $dispatcher);

    config(['whatsapp.app_secret' => whatsapp_secret()]);
    $body = whatsapp_webhook_payload('u2-dispatch-failure', 'phone-1');
    $request = Request::create('/api/webhooks/whatsapp', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => whatsapp_signature($body),
    ], $body);

    u4_webhook_service()->handle($request);

    $event = WebhookEvent::query()->where('provider_event_id', 'u2-dispatch-failure')->firstOrFail();

    expect($event->status)->toBe(WebhookEventStatus::Received)
        ->and($event->tenant_id)->toBeNull()
        ->and($event->error_code)->toBe('dispatch_failed');
});

test('U4-WS-INGEST-BUG-01: BUG-WEBHOOK-FOREACH RESOLVIDO — changes string ya no causa 500', function (): void {
    // Handler no arroja excepción y NO ingesta ningún evento (no-op).
    u4_ingest_malformed([['changes' => 'no-es-array']]);

    expect(WebhookEvent::query()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Malformed shape matrix (BUG-WEBHOOK-FOREACH regression, FASE 29 U4-HOTFIX)
// ---------------------------------------------------------------------------

test('U4-WS-SHAPE-01: changes = string se ignora sin 500', function (): void {
    u4_ingest_malformed([['changes' => 'no-es-array']]);

    expect(WebhookEvent::query()->count())->toBe(0);
});

test('U4-WS-SHAPE-02: changes = integer se ignora sin 500', function (): void {
    u4_ingest_malformed([['changes' => 12345]]);

    expect(WebhookEvent::query()->count())->toBe(0);
});

test('U4-WS-SHAPE-03: changes = null se ignora sin 500', function (): void {
    u4_ingest_malformed([['changes' => null]]);

    expect(WebhookEvent::query()->count())->toBe(0);
});

test('U4-WS-SHAPE-04: messages = string se ignora sin 500', function (): void {
    u4_ingest_malformed([['changes' => u4_messages('no-es-array')]]);

    expect(WebhookEvent::query()->count())->toBe(0);
});

test('U4-WS-SHAPE-05: messages = integer se ignora sin 500', function (): void {
    u4_ingest_malformed([['changes' => u4_messages(98765)]]);

    expect(WebhookEvent::query()->count())->toBe(0);
});

test('U4-WS-SHAPE-06: statuses = string se ignora sin 500', function (): void {
    u4_ingest_malformed([['changes' => u4_statuses('no-es-array')]]);

    expect(WebhookEvent::query()->count())->toBe(0);
});

test('U4-WS-SHAPE-07: statuses = integer se ignora sin 500', function (): void {
    u4_ingest_malformed([['changes' => u4_statuses(54321)]]);

    expect(WebhookEvent::query()->count())->toBe(0);
});

test('U4-WS-SHAPE-08: entry elemento malformado (string) se ignora sin 500', function (): void {
    // Un elemento de `entry` no-array se salta (ya cubierto), pero debe ser no-op.
    u4_ingest_malformed(['no-es-array', ['changes' => []]]);

    expect(WebhookEvent::query()->count())->toBe(0);
});

test('U4-WS-SHAPE-09: change elemento malformado se ignora sin 500', function (): void {
    u4_ingest_malformed([['changes' => [['field' => 'messages', 'value' => ['messages' => []]], 'no-es-array-change']]]);

    expect(WebhookEvent::query()->count())->toBe(0);
});

test('U4-WS-SHAPE-10: value malformado (string) se ignora sin 500', function (): void {
    u4_ingest_malformed([['changes' => u4_value('no-es-array')]]);

    expect(WebhookEvent::query()->count())->toBe(0);
});

test('U4-WS-SHAPE-11: entry top-level scalar (string) se ignora sin 500', function (): void {
    u4_ingest_malformed_entry_scalar('no-es-array');

    expect(WebhookEvent::query()->count())->toBe(0);
});
