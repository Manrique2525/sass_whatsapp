<?php

declare(strict_types=1);

use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\TenantPermission;
use App\Domain\Users\Enums\UserRole;
use App\Domain\WhatsApp\Models\WebhookEvent;
use App\Jobs\ProcessIncomingWhatsAppMessage;
use App\Jobs\ProcessWhatsAppStatusUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| FASE 6 — WHATSAPP WEBHOOK
|--------------------------------------------------------------------------
*/

const WEBHOOK_URL = '/api/webhooks/whatsapp';

function whatsapp_secret(): string
{
    return 'test-app-secret';
}

function whatsapp_signature(string $body): string
{
    return 'sha256='.hash_hmac('sha256', $body, whatsapp_secret());
}

/**
 * POST al webhook con el body JSON CRUDO y la firma X-Hub-Signature-256
 * correcta, tal como hace Meta.
 */
function post_whatsapp_webhook(string $body): TestResponse
{
    return test()->call(
        'POST',
        WEBHOOK_URL,
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => whatsapp_signature($body)],
        $body,
    );
}

function whatsapp_webhook_payload(string $messageId, string $phoneNumberId, bool $withStatus = false): string
{
    $messages = [[
        'from' => '15550000001',
        'id' => $messageId,
        'timestamp' => '1725000000',
        'type' => 'text',
        'text' => ['body' => 'Hola'],
    ]];

    $payload = [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => '104000000000000',
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'metadata' => [
                        'display_phone_number' => '15550000002',
                        'phone_number_id' => $phoneNumberId,
                    ],
                    'contacts' => [[
                        'profile' => ['name' => 'Cliente'],
                        'wa_id' => '15550000001',
                    ]],
                    'messages' => $messages,
                ],
            ]],
        ]],
    ];

    if ($withStatus) {
        $payload['entry'][0]['changes'][0]['value']['statuses'] = [[
            'id' => 'status-'.$messageId,
            'recipient_id' => '15550000002',
            'status' => 'delivered',
            'timestamp' => '1725000001',
        ]];
    }

    return json_encode($payload, JSON_THROW_ON_ERROR);
}

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

    expect(WebhookEvent::query()->where('provider_event_id', 'status-msg-2')->exists())->toBeTrue();

    $statusEvent = WebhookEvent::query()->where('provider_event_id', 'status-msg-2')->firstOrFail();
    expect($statusEvent->event_type->value)->toBe('status')
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
        ->and(WebhookEvent::query()->count())->toBe(1);
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
