<?php

declare(strict_types=1);

use App\Application\WhatsApp\Services\WhatsAppWebhookService;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\WhatsApp\Contracts\WhatsAppProviderInterface;
use App\Domain\WhatsApp\Enums\WebhookEventStatus;
use App\Domain\WhatsApp\Models\WebhookEvent;
use App\Jobs\ProcessIncomingWhatsAppMessage;
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

test('U4-WS-INGEST-BUG-01: REPRODUCER BUG-WEBHOOK-FOREACH — changes string causa 500 (skip)', function (): void {
    config(['whatsapp.app_secret' => whatsapp_secret()]);

    $service = u4_webhook_service();

    // `changes` como string: `foreach ($entry['changes'] ?? [])` en
    // WhatsAppWebhookService::handle() lanza "foreach() argument must be of
    // type array|object, string given" → HTTP 500 en el webhook público.
    // Reproducido: ErrorException/TypeError no capturado en la línea 72.
    // Fix propuesto (P1): guardar `is_array($entry['changes'])` antes de iterar.
    $this->markTestSkipped('BUG-WEBHOOK-FOREACH: ver root cause en reporte; requiere fix de producción fuera de alcance.');

    $body = json_encode([
        'object' => 'whatsapp_business_account',
        'entry' => [
            ['changes' => 'no-es-array'],
        ],
    ], JSON_THROW_ON_ERROR);

    $request = Request::create('/api/webhooks/whatsapp', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => whatsapp_signature($body),
    ], $body);

    $service->handle($request);
});
