<?php

declare(strict_types=1);

use App\Domain\Billing\Contracts\CapacityGuardInterface;
use App\Domain\Messages\Models\Message;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\TenantPermission;
use App\Domain\Users\Enums\UserRole;
use App\Domain\WhatsApp\Models\WebhookEvent;
use App\Jobs\ProcessIncomingWhatsAppMessage;
use App\Jobs\ProcessWhatsAppStatusUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeCapacityGuard;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(CapacityGuardInterface::class, new FakeCapacityGuard);
});

/*
|--------------------------------------------------------------------------
| FASE 6 — WHATSAPP WEBHOOK
|--------------------------------------------------------------------------
*/

test('WHATSAPP-1: la verificación GET responde el challenge como texto plano', function (): void {
    config(['whatsapp.verify_token' => 'mi-verify-token']);

    $this->get(WEBHOOK_URL.'?hub.mode=subscribe&hub.verify_token=mi-verify-token&hub.challenge=1234567890')
        ->assertOk()
        ->assertContent('1234567890');
});

test('WHATSAPP-2: la verificación GET con token inválido responde 403', function (): void {
    config(['whatsapp.verify_token' => 'mi-verify-token']);

    $this->get(WEBHOOK_URL.'?hub.mode=subscribe&hub.verify_token=otro-token&hub.challenge=123')
        ->assertStatus(403)
        ->assertJson(['code' => 'WHATSAPP_WEBHOOK_INVALID']);
});

test('WHATSAPP-U2-GET-01: la verificación GET requiere modo, token y challenge válidos', function (): void {
    config(['whatsapp.verify_token' => 'mi-verify-token']);

    $this->get(WEBHOOK_URL.'?hub.verify_token=mi-verify-token&hub.challenge=123')
        ->assertStatus(403);
    $this->get(WEBHOOK_URL.'?hub.mode=subscribe&hub.challenge=123')
        ->assertStatus(403);
    $this->get(WEBHOOK_URL.'?hub.mode=subscribe&hub.verify_token=mi-verify-token')
        ->assertStatus(403);
    $this->get(WEBHOOK_URL.'?hub.mode=wrong&hub.verify_token=mi-verify-token&hub.challenge=123')
        ->assertStatus(403);
});

test('WHATSAPP-U2-GET-02: parámetros GET no escalares se rechazan sin warnings ni challenge', function (): void {
    config(['whatsapp.verify_token' => 'mi-verify-token']);

    $this->get(WEBHOOK_URL.'?hub.mode[]=subscribe&hub.verify_token=mi-verify-token&hub.challenge=123')
        ->assertStatus(403);
});

test('WHATSAPP-3: un POST sin firma responde 401 con código estable', function (): void {
    $this->call('POST', WEBHOOK_URL, [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')
        ->assertStatus(401)
        ->assertJson(['code' => 'WHATSAPP_WEBHOOK_SIGNATURE_INVALID']);
});

test('WHATSAPP-4: un POST con firma incorrecta responde 401', function (): void {
    $this->call(
        'POST',
        WEBHOOK_URL,
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=firma-incorrecta'],
        '{}',
    )
        ->assertStatus(401)
        ->assertJson(['code' => 'WHATSAPP_WEBHOOK_SIGNATURE_INVALID']);
});

test('WHATSAPP-5: un JSON inválido con firma válida responde 200 (nunca 500)', function (): void {
    config(['whatsapp.app_secret' => whatsapp_secret()]);

    $body = 'no-es-json';
    post_whatsapp_webhook($body)
        ->assertOk()
        ->assertJson(['status' => 'ok']);
});

test('WHATSAPP-U2-ENV-01: envelope firmado malformado se acepta sin persistir ni encolar', function (): void {
    config(['whatsapp.app_secret' => whatsapp_secret()]);
    Queue::fake();

    foreach ([
        [],
        ['object' => 'wrong_object', 'entry' => []],
        ['object' => 'whatsapp_business_account'],
        ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'entry-1']]],
        ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages']]]]],
    ] as $payload) {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        post_whatsapp_webhook($body)->assertOk();
    }

    Queue::assertNothingPushed();
    expect(WebhookEvent::query()->count())->toBe(0);
});

test('WHATSAPP-U2-ENV-02: payload grande firmado se rechaza sin guardar el body', function (): void {
    config([
        'whatsapp.app_secret' => whatsapp_secret(),
        'whatsapp.webhook_max_payload_bytes' => 100,
    ]);
    Log::spy();

    $body = str_repeat('x', 101);

    post_whatsapp_webhook($body)->assertOk();

    Log::shouldHaveReceived('warning')
        ->with('whatsapp.webhook_invalid_payload', Mockery::on(function (array $context) use ($body): bool {
            return ($context['reason'] ?? null) === 'Payload de webhook inválido.'
                && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), $body);
        }));
    expect(WebhookEvent::query()->count())->toBe(0);
});

test('WHATSAPP-6: un mensaje entrante se ingesta, resuelve el tenant y se procesa', function (): void {
    config(['whatsapp.app_secret' => whatsapp_secret()]);

    $tenant = Tenant::factory()->create();
    make_whatsapp_setup($tenant);

    $body = whatsapp_webhook_payload('msg-1', 'phone-1');
    post_whatsapp_webhook($body)->assertOk();

    $event = WebhookEvent::query()->where('provider_event_id', 'msg-1')->firstOrFail();

    expect($event->tenant_id)->toBe($tenant->id)
        ->and($event->status->value)->toBe('processed')
        ->and($event->duplicate)->toBeFalse()
        ->and($event->event_type->value)->toBe('message');
});

test('WHATSAPP-7: los status updates se ingesan y procesan', function (): void {
    config(['whatsapp.app_secret' => whatsapp_secret()]);

    $tenant = Tenant::factory()->create();
    make_whatsapp_setup($tenant);

    $body = whatsapp_webhook_payload('msg-2', 'phone-1', withStatus: true);
    post_whatsapp_webhook($body)->assertOk();

    expect(WebhookEvent::query()->where('event_type', 'status')->exists())->toBeTrue();

    $statusEvent = WebhookEvent::query()->where('event_type', 'status')->firstOrFail();
    expect($statusEvent->provider_event_id)->toBe('status-msg-2|delivered|1725000001')
        ->and($statusEvent->event_type->value)->toBe('status')
        ->and($statusEvent->status->value)->toBe('processed');
});

test('WHATSAPP-8: un evento duplicado se ignora y se marca duplicate', function (): void {
    config(['whatsapp.app_secret' => whatsapp_secret()]);

    $tenant = Tenant::factory()->create();
    make_whatsapp_setup($tenant);

    $body = whatsapp_webhook_payload('msg-dupe', 'phone-1');

    post_whatsapp_webhook($body)->assertOk();
    post_whatsapp_webhook($body)->assertOk();

    $events = WebhookEvent::query()->where('provider_event_id', 'msg-dupe')->get();

    expect($events)->toHaveCount(1)
        ->and($events->first()->duplicate)->toBeTrue()
        ->and(WebhookEvent::query()->count())->toBe(1)
        ->and(Message::query()->withoutTenantScope()->where('provider_message_id', 'msg-dupe')->count())->toBe(1);
});

test('WHATSAPP-U2-IDEMP-01: múltiples entries, mensajes y statuses se ingesan individualmente', function (): void {
    config(['whatsapp.app_secret' => whatsapp_secret()]);
    Queue::fake();

    $tenant = Tenant::factory()->create();
    make_whatsapp_setup($tenant);

    $first = json_decode(whatsapp_webhook_payload('msg-batch-1', 'phone-1', true), true, 512, JSON_THROW_ON_ERROR);
    $second = json_decode(whatsapp_webhook_payload('msg-batch-2', 'phone-1', true), true, 512, JSON_THROW_ON_ERROR);
    $payload = [
        'object' => 'whatsapp_business_account',
        'entry' => [
            $first['entry'][0],
            $second['entry'][0],
        ],
    ];
    $payload['entry'][0]['changes'][0]['value']['messages'][] = [
        'from' => '15550000001',
        'id' => 'msg-batch-3',
        'timestamp' => '1725000002',
        'type' => 'text',
        'text' => ['body' => 'Segundo'],
    ];

    post_whatsapp_webhook(json_encode($payload, JSON_THROW_ON_ERROR))->assertOk();

    expect(WebhookEvent::query()->where('event_type', 'message')->count())->toBe(3)
        ->and(WebhookEvent::query()->where('event_type', 'status')->count())->toBe(2);
    Queue::assertPushed(ProcessIncomingWhatsAppMessage::class, 3);
    Queue::assertPushed(ProcessWhatsAppStatusUpdate::class, 2);
});

test('WHATSAPP-U2-IDEMP-02: replay exacto de status no duplica evento ni trabajo', function (): void {
    config(['whatsapp.app_secret' => whatsapp_secret()]);
    Queue::fake();

    $tenant = Tenant::factory()->create();
    make_whatsapp_setup($tenant);
    $body = whatsapp_webhook_payload('msg-status-dupe', 'phone-1', withStatus: true);

    post_whatsapp_webhook($body)->assertOk();
    post_whatsapp_webhook($body)->assertOk();

    expect(WebhookEvent::query()->where('event_type', 'status')->count())->toBe(1)
        ->and(WebhookEvent::query()->where('event_type', 'status')->firstOrFail()->duplicate)->toBeTrue();
    Queue::assertPushed(ProcessWhatsAppStatusUpdate::class, 1);
});

test('WHATSAPP-9: un evento sin phone_number_id se marca failed', function (): void {
    config(['whatsapp.app_secret' => whatsapp_secret()]);

    $payload = [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => '104000000000000',
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'metadata' => [],
                    'messages' => [[
                        'from' => '15550000001',
                        'id' => 'msg-no-metadata',
                        'type' => 'text',
                    ]],
                ],
            ]],
        ]],
    ];

    post_whatsapp_webhook(json_encode($payload, JSON_THROW_ON_ERROR))->assertOk();

    $event = WebhookEvent::query()->where('provider_event_id', 'msg-no-metadata')->firstOrFail();

    expect($event->status->value)->toBe('failed')
        ->and($event->error_code)->toBe('missing_phone_number_id');
});

test('WHATSAPP-10: un evento con phone_id desconocido se marca failed sin romper el flujo', function (): void {
    config(['whatsapp.app_secret' => whatsapp_secret()]);

    $body = whatsapp_webhook_payload('msg-unknown-phone', 'phone-inexistente');
    post_whatsapp_webhook($body)->assertOk();

    $event = WebhookEvent::query()->where('provider_event_id', 'msg-unknown-phone')->firstOrFail();

    expect($event->status->value)->toBe('failed')
        ->and($event->error_code)->toBe('unknown_phone_number_id')
        ->and($event->tenant_id)->toBeNull();
});

test('WHATSAPP-11: CRITICO — un webhook resuelve el tenant por phone_id, nunca expone datos de otro tenant', function (): void {
    config(['whatsapp.app_secret' => whatsapp_secret()]);

    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    make_whatsapp_setup($tenantA);

    $body = whatsapp_webhook_payload('msg-isolation', 'phone-1');
    post_whatsapp_webhook($body)->assertOk();

    $event = WebhookEvent::query()->where('provider_event_id', 'msg-isolation')->firstOrFail();

    expect($event->tenant_id)->toBe($tenantA->id)
        ->and($event->tenant_id)->not->toBe($tenantB->id);
});

test('WHATSAPP-U2-TENANCY-01: tenant_id y WABA falsos del payload se ignoran', function (): void {
    config(['whatsapp.app_secret' => whatsapp_secret()]);

    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    make_whatsapp_setup($tenantA);

    $payload = json_decode(whatsapp_webhook_payload('msg-forged-tenant', 'phone-1'), true, 512, JSON_THROW_ON_ERROR);
    $payload['tenant_id'] = $tenantB->id;
    $payload['entry'][0]['changes'][0]['value']['metadata']['waba_id'] = 'waba-of-tenant-b';

    post_whatsapp_webhook(json_encode($payload, JSON_THROW_ON_ERROR))->assertOk();

    $event = WebhookEvent::query()->where('provider_event_id', 'msg-forged-tenant')->firstOrFail();

    expect($event->tenant_id)->toBe($tenantA->id)
        ->and($event->tenant_id)->not->toBe($tenantB->id);
});

test('WHATSAPP-U2-INGEST-01: payload conserva solo el registro mínimo para replay', function (): void {
    config(['whatsapp.app_secret' => whatsapp_secret()]);

    $tenant = Tenant::factory()->create();
    make_whatsapp_setup($tenant);

    $body = whatsapp_webhook_payload('msg-minimal-payload', 'phone-1');
    post_whatsapp_webhook($body)->assertOk();

    $event = WebhookEvent::query()->where('provider_event_id', 'msg-minimal-payload')->firstOrFail();

    expect($event->payload)->toHaveKeys(['phone_number_id', 'type', 'data'])
        ->and($event->payload)->not->toHaveKey('entry')
        ->and($event->payload)->not->toHaveKey('object');
});

test('WHATSAPP-12: los jobs correctos se despachan por tipo de evento', function (): void {
    Queue::fake();

    config(['whatsapp.app_secret' => whatsapp_secret()]);

    $tenant = Tenant::factory()->create();
    make_whatsapp_setup($tenant);

    post_whatsapp_webhook(whatsapp_webhook_payload('msg-jobs', 'phone-1', withStatus: true))->assertOk();

    Queue::assertPushed(ProcessIncomingWhatsAppMessage::class);
    Queue::assertPushed(ProcessWhatsAppStatusUpdate::class);
});

test('WHATSAPP-13: un evento de otro field se ignora silenciosamente', function (): void {
    config(['whatsapp.app_secret' => whatsapp_secret()]);

    $payload = [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => '104000000000000',
            'changes' => [[
                'field' => 'account_update',
                'value' => ['event' => 'ban'],
            ]],
        ]],
    ];

    post_whatsapp_webhook(json_encode($payload, JSON_THROW_ON_ERROR))->assertOk();

    expect(WebhookEvent::query()->count())->toBe(0);
});

test('WHATSAPP-14: los permisos de la matriz incluyen whatsapp.view/manage', function (): void {
    $ownerPerms = TenantPermission::permissionsForRole(UserRole::Owner);
    $adminPerms = TenantPermission::permissionsForRole(UserRole::Admin);
    $agentPerms = TenantPermission::permissionsForRole(UserRole::Agent);

    expect($ownerPerms)->toContain(TenantPermission::ViewWhatsApp)
        ->and($ownerPerms)->toContain(TenantPermission::ManageWhatsApp)
        ->and($adminPerms)->toContain(TenantPermission::ViewWhatsApp)
        ->and($adminPerms)->toContain(TenantPermission::ManageWhatsApp)
        ->and($agentPerms)->toContain(TenantPermission::ViewWhatsApp)
        ->and($agentPerms)->not->toContain(TenantPermission::ManageWhatsApp);
});
