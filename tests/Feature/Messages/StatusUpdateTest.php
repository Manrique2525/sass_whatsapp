<?php

declare(strict_types=1);

use App\Application\Messages\Services\MessageService;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Conversations\Enums\ConversationStatus;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Messages\Enums\MessageStatus;
use App\Domain\Messages\Models\Message;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\WhatsApp\Models\WebhookEvent;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| FASE 9 — MENSAJES: STATUS UPDATES (nunca crean mensajes, solo actualizan)
|--------------------------------------------------------------------------
*/

function statuses_webhook_payload(string $messageId, array $statuses): string
{
    $items = [];

    foreach ($statuses as $status) {
        $items[] = [
            'id' => $messageId,
            'recipient_id' => '15550000002',
            'status' => $status,
            'timestamp' => '1725000000',
        ];
    }

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
                        'phone_number_id' => 'phone-1',
                    ],
                    'statuses' => $items,
                ],
            ]],
        ]],
    ];

    return json_encode($payload, JSON_THROW_ON_ERROR);
}

/**
 * Crea contact + conversation (open) + mensaje saliente ya `sent` con el
 * provider_message_id de Meta, listo para recibir status updates.
 */
function make_sent_message(Tenant $tenant, string $providerMessageId, string $conversationStatus = 'open', string $phone = '+15550000001'): Message
{
    $contact = make_contact($tenant, ['phone' => $phone]);
    $conversation = make_conversation($tenant, $contact, ['status' => $conversationStatus]);

    TenantContext::setId($tenant->id);

    try {
        return Message::query()->create([
            'conversation_id' => $conversation->id,
            'provider_message_id' => $providerMessageId,
            'direction' => 'outbound',
            'type' => 'text',
            'status' => 'sent',
            'body' => 'Hola',
            'sent_at' => now(),
        ]);
    } finally {
        TenantContext::clear();
    }
}

test('STAT-1: delivered actualiza status y delivered_at del mensaje', function (): void {
    config(['whatsapp.app_secret' => whatsapp_secret()]);

    $tenant = Tenant::factory()->create();
    make_whatsapp_setup($tenant);
    make_sent_message($tenant, 'wamid-out-1');

    post_whatsapp_webhook(statuses_webhook_payload('wamid-out-1', ['delivered']))->assertOk();

    $message = Message::query()->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->where('provider_message_id', 'wamid-out-1')
        ->firstOrFail();

    expect($message->status)->toBe(MessageStatus::Delivered)
        ->and($message->delivered_at)->not->toBeNull()
        ->and(Message::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('STAT-2: read actualiza status y read_at', function (): void {
    config(['whatsapp.app_secret' => whatsapp_secret()]);

    $tenant = Tenant::factory()->create();
    make_whatsapp_setup($tenant);
    make_sent_message($tenant, 'wamid-out-2');

    post_whatsapp_webhook(statuses_webhook_payload('wamid-out-2', ['read']))->assertOk();

    $message = Message::query()->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->where('provider_message_id', 'wamid-out-2')
        ->firstOrFail();

    expect($message->status)->toBe(MessageStatus::Read)
        ->and($message->read_at)->not->toBeNull();
});

test('STAT-3: failed marca el mensaje y pasa la conversación a pending', function (): void {
    config(['whatsapp.app_secret' => whatsapp_secret()]);

    $tenant = Tenant::factory()->create();
    make_whatsapp_setup($tenant);
    $message = make_sent_message($tenant, 'wamid-out-3');

    post_whatsapp_webhook(statuses_webhook_payload('wamid-out-3', ['failed']))->assertOk();

    $message->refresh();

    expect($message->status)->toBe(MessageStatus::Failed)
        ->and($message->failed_at)->not->toBeNull();

    $conversation = Conversation::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();

    expect($conversation->status)->toBe(ConversationStatus::Pending);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'message.status_updated',
        'tenant_id' => $tenant->id,
        'subject_id' => $message->id,
    ]);
});

test('STAT-4: un status de un mensaje inexistente es un no-op (no crea mensajes)', function (): void {
    config(['whatsapp.app_secret' => whatsapp_secret()]);

    $tenant = Tenant::factory()->create();
    make_whatsapp_setup($tenant);

    post_whatsapp_webhook(statuses_webhook_payload('wamid-desconocido', ['delivered']))->assertOk();

    $event = WebhookEvent::query()->where('event_type', 'status')->firstOrFail();

    expect($event->status->value)->toBe('processed')
        ->and(Message::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('STAT-5: delivered y read del mismo mensaje se procesan ambos (dedupe compuesto)', function (): void {
    config(['whatsapp.app_secret' => whatsapp_secret()]);

    $tenant = Tenant::factory()->create();
    make_whatsapp_setup($tenant);
    make_sent_message($tenant, 'wamid-out-5');

    post_whatsapp_webhook(statuses_webhook_payload('wamid-out-5', ['delivered', 'read']))->assertOk();

    $events = WebhookEvent::query()->where('event_type', 'status')->get();

    expect($events)->toHaveCount(2)
        ->and($events->every(fn ($e) => $e->status->value === 'processed'))->toBeTrue();

    $message = Message::query()->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->where('provider_message_id', 'wamid-out-5')
        ->firstOrFail();

    expect($message->status)->toBe(MessageStatus::Read)
        ->and($message->delivered_at)->not->toBeNull()
        ->and($message->read_at)->not->toBeNull()
        ->and(Message::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('STAT-6: un status entregado dos veces es idempotente', function (): void {
    config(['whatsapp.app_secret' => whatsapp_secret()]);

    $tenant = Tenant::factory()->create();
    make_whatsapp_setup($tenant);
    make_sent_message($tenant, 'wamid-out-6');

    $body = statuses_webhook_payload('wamid-out-6', ['delivered']);

    post_whatsapp_webhook($body)->assertOk();
    post_whatsapp_webhook($body)->assertOk();

    expect(WebhookEvent::query()->where('event_type', 'status')->count())->toBe(1);

    $message = Message::query()->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->where('provider_message_id', 'wamid-out-6')
        ->firstOrFail();

    expect($message->status)->toBe(MessageStatus::Delivered)
        ->and(Message::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('STAT-7: un status con estado desconocido es un no-op y no rompe el evento', function (): void {
    config(['whatsapp.app_secret' => whatsapp_secret()]);

    $tenant = Tenant::factory()->create();
    make_whatsapp_setup($tenant);
    make_sent_message($tenant, 'wamid-out-7');

    post_whatsapp_webhook(statuses_webhook_payload('wamid-out-7', ['deleted']))->assertOk();

    $message = Message::query()->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->where('provider_message_id', 'wamid-out-7')
        ->firstOrFail();

    expect($message->status)->toBe(MessageStatus::Sent)
        ->and(WebhookEvent::query()->where('event_type', 'status')->firstOrFail()->status->value)->toBe('processed');
});

test('STAT-8: el service aplica status solo al mensaje del tenant correcto', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    make_sent_message($tenantA, 'wamid-cross');
    make_sent_message($tenantB, 'wamid-cross');

    $service = app(MessageService::class);

    $service->handleStatusUpdate($tenantA, [
        'id' => 'wamid-cross',
        'status' => 'read',
        'timestamp' => '1725000000',
    ]);

    $messageA = Message::query()->withoutTenantScope()->where('tenant_id', $tenantA->id)->firstOrFail();
    $messageB = Message::query()->withoutTenantScope()->where('tenant_id', $tenantB->id)->firstOrFail();

    expect($messageA->status)->toBe(MessageStatus::Read)
        ->and($messageB->status)->toBe(MessageStatus::Sent);
});

test('U3-STAT-01: status updates avanzan monotónicamente y no regresan', function (): void {
    $tenant = Tenant::factory()->create();
    $message = make_sent_message($tenant, 'wamid-monotonic');
    $service = app(MessageService::class);

    $service->handleStatusUpdate($tenant, ['id' => $message->provider_message_id, 'status' => 'delivered', 'timestamp' => '1725000001']);
    $service->handleStatusUpdate($tenant, ['id' => $message->provider_message_id, 'status' => 'read', 'timestamp' => '1725000002']);
    $service->handleStatusUpdate($tenant, ['id' => $message->provider_message_id, 'status' => 'delivered', 'timestamp' => '1725000003']);
    $service->handleStatusUpdate($tenant, ['id' => $message->provider_message_id, 'status' => 'sent', 'timestamp' => '1725000004']);

    $message->refresh();

    expect($message->status)->toBe(MessageStatus::Read)
        ->and($message->read_at)->not->toBeNull();
});

test('U3-STAT-02: status read puede saltar delivered y luego permanece final', function (): void {
    $tenant = Tenant::factory()->create();
    $message = make_sent_message($tenant, 'wamid-skip-delivered');
    $service = app(MessageService::class);

    $service->handleStatusUpdate($tenant, ['id' => $message->provider_message_id, 'status' => 'read', 'timestamp' => '1725000002']);
    $service->handleStatusUpdate($tenant, ['id' => $message->provider_message_id, 'status' => 'delivered', 'timestamp' => '1725000003']);

    $message->refresh();

    expect($message->status)->toBe(MessageStatus::Read)
        ->and($message->delivered_at)->toBeNull();
});

test('U3-STAT-03: failed conserva detalles seguros y no regresa desde delivered/read', function (): void {
    $tenant = Tenant::factory()->create();
    $message = make_sent_message($tenant, 'wamid-failed-details');
    $service = app(MessageService::class);

    $service->handleStatusUpdate($tenant, [
        'id' => $message->provider_message_id,
        'status' => 'failed',
        'timestamp' => '1725000001',
        'errors' => [[
            'code' => 131000,
            'title' => 'Message failed',
            'message' => 'Provider detail +15550000001',
            'error_data' => ['details' => 'safe detail'],
        ]],
    ]);

    $message->refresh();

    expect($message->status)->toBe(MessageStatus::Failed)
        ->and($message->metadata['status_failure']['provider_code'])->toBe('131000')
        ->and($message->metadata['status_failure']['message'])->not->toContain('+15550000001');

    $service->handleStatusUpdate($tenant, ['id' => $message->provider_message_id, 'status' => 'delivered', 'timestamp' => '1725000002']);
    $message->refresh();

    expect($message->status)->toBe(MessageStatus::Failed);
});

test('U3-STAT-04: status repetido o de timestamp distinto no emite transición adicional', function (): void {
    $tenant = Tenant::factory()->create();
    $message = make_sent_message($tenant, 'wamid-status-repeat');
    $service = app(MessageService::class);

    $service->handleStatusUpdate($tenant, ['id' => $message->provider_message_id, 'status' => 'delivered', 'timestamp' => '1725000001']);
    $firstDeliveredAt = $message->fresh()->delivered_at;
    $auditCount = DB::table('audit_logs')->where('action', 'message.status_updated')->count();
    $service->handleStatusUpdate($tenant, ['id' => $message->provider_message_id, 'status' => 'delivered', 'timestamp' => '1725000002']);
    $message->refresh();

    expect($message->delivered_at)->toEqual($firstDeliveredAt)
        ->and(DB::table('audit_logs')->where('action', 'message.status_updated')->count())->toBe($auditCount);
});

test('U3-STAT-05: failed tardío no regresa delivered ni read', function (): void {
    $tenant = Tenant::factory()->create();
    $delivered = make_sent_message($tenant, 'wamid-failed-after-delivered');
    $read = make_sent_message($tenant, 'wamid-failed-after-read', phone: '+15550000002');
    $service = app(MessageService::class);

    $service->handleStatusUpdate($tenant, ['id' => $delivered->provider_message_id, 'status' => 'delivered', 'timestamp' => '1725000001']);
    $service->handleStatusUpdate($tenant, ['id' => $delivered->provider_message_id, 'status' => 'failed', 'timestamp' => '1725000002']);
    $service->handleStatusUpdate($tenant, ['id' => $read->provider_message_id, 'status' => 'read', 'timestamp' => '1725000001']);
    $service->handleStatusUpdate($tenant, ['id' => $read->provider_message_id, 'status' => 'failed', 'timestamp' => '1725000002']);

    expect($delivered->fresh()->status)->toBe(MessageStatus::Delivered)
        ->and($read->fresh()->status)->toBe(MessageStatus::Read);
});
