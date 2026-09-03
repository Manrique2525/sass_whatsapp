<?php

declare(strict_types=1);

use App\Application\WhatsApp\Services\WhatsAppMessagingService;
use App\Application\WhatsApp\Services\WhatsAppWebhookService;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Domain\WhatsApp\Enums\WebhookEventStatus;
use App\Domain\WhatsApp\Models\WebhookEvent;
use App\Domain\WhatsApp\Models\WhatsAppAccount;
use App\Infrastructure\Observability\MetricsRecorder;
use App\Infrastructure\Tenancy\TenantContext;
use App\Jobs\ProcessIncomingWhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| FASE 31 U6 — OPERATIONS, OBSERVABILITY & PRODUCTION READINESS
|--------------------------------------------------------------------------
*/

function ops_wa_url(Tenant $tenant, string $action = ''): string
{
    $base = '/api/v1/tenants/'.$tenant->id.'/whatsapp';

    return $action === '' ? $base : $base.'/'.$action;
}

/**
 * Crea un evento de webhook directamente (tabla de plataforma, sin scope tenant).
 */
function make_webhook_event(Tenant $tenant, string $providerEventId, string $status, array $extra = []): WebhookEvent
{
    return WebhookEvent::query()->create(array_merge([
        'provider_event_id' => $providerEventId,
        'tenant_id' => $tenant->id,
        'payload' => ['phone_number_id' => 'phone-1'],
        'status' => $status,
        'event_type' => 'message',
        'duplicate' => false,
    ], $extra));
}

/**
 * Envía un texto vía WhatsAppMessagingService con el TenantContext activo.
 */
function ops_send_text(User $user, Tenant $tenant, string $to, string $text): mixed
{
    $service = app(WhatsAppMessagingService::class);

    TenantContext::setId($tenant->id);

    try {
        return $service->sendText($user, $tenant, $to, $text);
    } finally {
        TenantContext::clear();
    }
}

/*
|--------------------------------------------------------------------------
| Contadores de observabilidad (MetricsRecorder)
|--------------------------------------------------------------------------
*/

test('U6-METRIC-01: increment y gauge registran contadores sobre el cache', function (): void {
    Cache::forget('observability:metrics:whatsapp.webhook.received');
    Cache::forget('observability:metrics:whatsapp.phone.health.rating.green');

    $metrics = app(MetricsRecorder::class);

    $metrics->increment('whatsapp.webhook.received');
    $metrics->increment('whatsapp.webhook.received');
    $metrics->gauge('whatsapp.phone.health.rating.green', 2);

    expect($metrics->value('whatsapp.webhook.received'))->toBe(2)
        ->and($metrics->value('whatsapp.phone.health.rating.green'))->toBe(2);
});

test('U6-METRIC-02: value devuelve 0 para una métrica inexistente o fallo de lectura', function (): void {
    Cache::forget('observability:metrics:whatsapp.never.touched');

    expect(app(MetricsRecorder::class)->value('whatsapp.never.touched'))->toBe(0);
});

test('U6-METRIC-03: las métricas se desactivan con observability.metrics_enabled=false', function (): void {
    $metrics = app(MetricsRecorder::class);
    $key = 'observability:metrics:whatsapp.webhook.duplicate';
    Cache::forget($key);

    config()->set('observability.metrics_enabled', false);
    try {
        $metrics->increment('whatsapp.webhook.duplicate');
        expect($metrics->value('whatsapp.webhook.duplicate'))->toBe(0);
    } finally {
        config()->set('observability.metrics_enabled', true);
    }
});

test('U6-METRIC-04: una solicitud exitosa del provider registra contador por operación', function (): void {
    Http::fake([
        'graph.facebook.com/*/phone-1/messages' => Http::response([
            'messaging_product' => 'whatsapp',
            'contacts' => [['input' => '15550000001', 'wa_id' => '15550000001']],
            'messages' => [['id' => 'wamid-abcd']],
        ], 200),
    ]);

    Cache::forget('observability:metrics:whatsapp.provider.send_text');
    Cache::forget('observability:metrics:whatsapp.provider.send_text.success');

    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    make_whatsapp_setup($tenant);

    $this->actingAs($owner);
    $result = ops_send_text($owner, $tenant, '15550000001', 'Hola cliente');

    expect($result->providerMessageId)->toBe('wamid-abcd');

    $metrics = app(MetricsRecorder::class);
    expect($metrics->value('whatsapp.provider.send_text'))->toBeGreaterThan(0)
        ->and($metrics->value('whatsapp.provider.send_text.success'))->toBeGreaterThan(0);
});

/*
|--------------------------------------------------------------------------
| Replay operator de webhook — autorización y aislamiento
|--------------------------------------------------------------------------
*/

test('U6-REPLAY-01: un owner puede listar la cola y replayar failed', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    make_whatsapp_setup($tenant);
    make_webhook_event($tenant, 'ev-failed-1', WebhookEventStatus::Failed->value);

    $this->actingAs($owner)
        ->getJson(ops_wa_url($tenant, 'webhook-events/queue'))
        ->assertOk()
        ->assertJson(['status' => 'pending', 'count' => 1]);

    $this->actingAs($owner)
        ->postJson(ops_wa_url($tenant, 'webhook-events/replay'))
        ->assertOk()
        ->assertJsonPath('replayed', 1);

    Queue::assertPushed(ProcessIncomingWhatsAppMessage::class);

    $event = WebhookEvent::query()->where('provider_event_id', 'ev-failed-1')->firstOrFail();
    expect($event->status->value)->toBe(WebhookEventStatus::Enqueued->value);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'whatsapp.webhook.replayed',
        'tenant_id' => $tenant->id,
        'actor_user_id' => $owner->id,
    ]);
});

test('U6-REPLAY-02: un admin también puede listar y replayar', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $admin = User::factory()->create();
    make_tenant_member($admin, $tenant, 'admin');

    make_whatsapp_setup($tenant);
    make_webhook_event($tenant, 'ev-admin', WebhookEventStatus::Failed->value);

    $this->actingAs($admin)
        ->postJson(ops_wa_url($tenant, 'webhook-events/replay'))
        ->assertOk()
        ->assertJsonPath('replayed', 1);
});

test('U6-REPLAY-03: un agente NO puede listar ni replayar (403)', function (): void {
    $tenant = Tenant::factory()->create();
    $agent = User::factory()->create();
    make_tenant_member($agent, $tenant, 'agent');

    $this->actingAs($agent)
        ->getJson(ops_wa_url($tenant, 'webhook-events/queue'))
        ->assertStatus(403)
        ->assertJson(['code' => 'PERMISSION_DENIED']);

    $this->actingAs($agent)
        ->postJson(ops_wa_url($tenant, 'webhook-events/replay'))
        ->assertStatus(403)
        ->assertJson(['code' => 'PERMISSION_DENIED']);
});

test('U6-REPLAY-04: CRITICO — nunca se lista ni replaya eventos de otro tenant (404)', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    make_tenant_member($ownerA, $tenantA, 'owner');
    make_tenant_member($ownerB, $tenantB, 'owner');

    make_webhook_event($tenantB, 'ev-b-secret', WebhookEventStatus::Failed->value);

    // A no ve la cola ni replaya la de B.
    $this->actingAs($ownerA)
        ->getJson(ops_wa_url($tenantB, 'webhook-events/queue'))
        ->assertStatus(404);

    $this->actingAs($ownerA)
        ->postJson(ops_wa_url($tenantB, 'webhook-events/replay'))
        ->assertStatus(404);

    // El evento de B sigue failed (no fue tocado).
    $event = WebhookEvent::query()->where('provider_event_id', 'ev-b-secret')->firstOrFail();
    expect($event->status->value)->toBe(WebhookEventStatus::Failed->value);
});

/*
|--------------------------------------------------------------------------
| Replay — elegibilidad de estados y reencolado idempotente
|--------------------------------------------------------------------------
*/

test('U6-REPLAY-05: replay de un evento failed re-encola y audita', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    make_whatsapp_setup($tenant);
    $event = make_webhook_event($tenant, 'ev-replay-ok', WebhookEventStatus::Failed->value);

    $this->actingAs($owner);

    $service = app(WhatsAppWebhookService::class);
    expect($service->replayEvent($event))->toBeTrue();

    $event->refresh();
    expect($event->status->value)->toBe(WebhookEventStatus::Enqueued->value)
        ->and($event->tenant_id)->toBe($tenant->id);

    Queue::assertPushed(ProcessIncomingWhatsAppMessage::class);
});

test('U6-REPLAY-06: los eventos processed y enqueued NO son elegibles para replay', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $processed = make_webhook_event($tenant, 'ev-processed', WebhookEventStatus::Processed->value);
    $enqueued = make_webhook_event($tenant, 'ev-enqueued', WebhookEventStatus::Enqueued->value);

    $service = app(WhatsAppWebhookService::class);

    expect($service->replayEvent($processed))->toBeFalse();
    expect($service->replayEvent($enqueued))->toBeFalse();

    // El estado NO cambia (no hubo doble trabajo).
    expect($processed->refresh()->status->value)->toBe(WebhookEventStatus::Processed->value)
        ->and($enqueued->refresh()->status->value)->toBe(WebhookEventStatus::Enqueued->value);
});

test('U6-REPLAY-07: la cola refleja limpieza cuando no hay nada pendiente', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    make_webhook_event($tenant, 'ev-clean', WebhookEventStatus::Processed->value);

    $this->actingAs($owner)
        ->getJson(ops_wa_url($tenant, 'webhook-events/queue'))
        ->assertOk()
        ->assertJson(['status' => 'clean', 'count' => 0]);

    // Sin eventos también es clean y 0.
    $tenant2 = Tenant::factory()->create();
    $owner2 = User::factory()->create();
    make_tenant_member($owner2, $tenant2, 'owner');

    $this->actingAs($owner2)
        ->getJson(ops_wa_url($tenant2, 'webhook-events/queue'))
        ->assertOk()
        ->assertJson(['status' => 'clean', 'count' => 0]);
});

test('U6-REPLAY-08: un evento failed cuyo número ya no existe queda failed, sin doble encolado', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    // Sin make_whatsapp_setup → 'phone-1' no existe → el replay re-falla.
    $event = make_webhook_event($tenant, 'ev-orphan', WebhookEventStatus::Failed->value);

    $service = app(WhatsAppWebhookService::class);
    expect($service->replayEvent($event))->toBeFalse();

    $event->refresh();
    expect($event->status->value)->toBe(WebhookEventStatus::Failed->value)
        ->and($event->error_code)->toBe('unknown_phone_number_id');

    Queue::assertNotPushed(ProcessIncomingWhatsAppMessage::class);
});

/*
|--------------------------------------------------------------------------
| Salud de números de WhatsApp (phone health)
|--------------------------------------------------------------------------
*/

test('U6-PHONE-01: un owner refresca la salud sin desconectar el número', function (): void {
    Http::fake([
        'graph.facebook.com/*/phone-1*' => Http::response([
            'id' => 'phone-1',
            'verified_name' => 'Negocio Actualizado',
            'display_phone_number' => '15550000002',
            'quality_rating' => 'YELLOW',
            'status' => 'connected',
        ], 200),
    ]);

    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $setup = make_whatsapp_setup($tenant);

    $this->actingAs($owner)
        ->postJson(ops_wa_url($tenant, 'phone-health'))
        ->assertOk()
        ->assertJsonPath('checked', 1)
        ->assertJsonPath('degraded', 1)
        ->assertJsonPath('flagged.0.quality_rating', 'YELLOW');

    // El número NUNCA se desconecta; persiste solo campos informativos.
    $phone = $setup['phone']->refresh();
    expect($phone->status->value)->toBe('connected')
        ->and($phone->quality_rating)->toBe('YELLOW')
        ->and($phone->verified_name)->toBe('Negocio Actualizado');

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'whatsapp.phone.health.check',
        'tenant_id' => $tenant->id,
        'actor_user_id' => $owner->id,
    ]);
});

test('U6-PHONE-02: un agente NO puede refrescar salud (403)', function (): void {
    $tenant = Tenant::factory()->create();
    $agent = User::factory()->create();
    make_tenant_member($agent, $tenant, 'agent');

    make_whatsapp_setup($tenant);

    $this->actingAs($agent)
        ->postJson(ops_wa_url($tenant, 'phone-health'))
        ->assertStatus(403)
        ->assertJson(['code' => 'PERMISSION_DENIED']);
});

test('U6-PHONE-03: sin cuenta conectada responde 409 WHATSAPP_NOT_CONNECTED', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $this->actingAs($owner)
        ->postJson(ops_wa_url($tenant, 'phone-health'))
        ->assertStatus(409)
        ->assertJson(['code' => 'WHATSAPP_NOT_CONNECTED']);
});

test('U6-PHONE-04: CRITICO — un tenant no consulta la salud de otro (404)', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    make_tenant_member($ownerA, $tenantA, 'owner');
    make_tenant_member($ownerB, $tenantB, 'owner');

    make_whatsapp_setup($tenantB);

    $this->actingAs($ownerA)
        ->postJson(ops_wa_url($tenantB, 'phone-health'))
        ->assertStatus(404);

    // B sigue conectado (nunca se tocó).
    $account = WhatsAppAccount::query()->withoutTenantScope()->where('tenant_id', $tenantB->id)->firstOrFail();
    expect($account->isConnected())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Readiness — los endpoints operator NO afectan /health ni /ready (FASE 28)
|--------------------------------------------------------------------------
*/

test('U6-READY-01: /health y /ready siguen disponibles y no dependen de Meta', function (): void {
    Http::fake();

    $this->get('/health')->assertOk();
    $this->get('/ready')->assertOk();

    Http::assertNothingSent();
});
