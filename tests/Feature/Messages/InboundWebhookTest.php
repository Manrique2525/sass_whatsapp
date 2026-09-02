<?php

declare(strict_types=1);

use App\Application\Messages\Services\MessageService;
use App\Domain\Billing\Contracts\CapacityGuardInterface;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Conversations\Enums\ConversationStatus;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Messages\Enums\MessageDirection;
use App\Domain\Messages\Enums\MessageStatus;
use App\Domain\Messages\Enums\MessageType;
use App\Domain\Messages\Models\Message;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\WhatsApp\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeCapacityGuard;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(CapacityGuardInterface::class, new FakeCapacityGuard);
});

/*
|--------------------------------------------------------------------------
| FASE 9 — MENSAJES: INBOUND (webhook → contact → conversation → message)
|--------------------------------------------------------------------------
*/

function inbound_data(string $id, string $body = 'Hola', string $from = '15550000001'): array
{
    return [
        'id' => $id,
        'from' => $from,
        'timestamp' => '1725000000',
        'type' => 'text',
        'text' => ['body' => $body],
    ];
}

test('MSG-1: un mensaje entrante por webhook persiste contact, conversation y message', function (): void {
    config(['whatsapp.app_secret' => whatsapp_secret()]);

    $tenant = Tenant::factory()->create();
    make_whatsapp_setup($tenant);

    post_whatsapp_webhook(whatsapp_webhook_payload('msg-e2e', 'phone-1'))->assertOk();

    $event = WebhookEvent::query()->where('provider_event_id', 'msg-e2e')->firstOrFail();

    expect($event->status->value)->toBe('processed');

    $contact = Contact::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();

    expect($contact->phone)->toBe('+15550000001');

    $conversation = Conversation::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();

    expect($conversation->contact_id)->toBe($contact->id)
        ->and($conversation->status)->toBe(ConversationStatus::Open)
        ->and($conversation->last_message_at)->not->toBeNull()
        ->and($conversation->last_interaction_at)->not->toBeNull();

    $message = Message::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();

    expect($message->provider_message_id)->toBe('msg-e2e')
        ->and($message->direction)->toBe(MessageDirection::Inbound)
        ->and($message->type)->toBe(MessageType::Text)
        ->and($message->status)->toBe(MessageStatus::Delivered)
        ->and($message->body)->toBe('Hola')
        ->and($message->delivered_at)->not->toBeNull()
        ->and($message->metadata['from'])->toBe('15550000001');
});

test('MSG-2: el mismo provider_message_id no persiste el mensaje dos veces', function (): void {
    $tenant = Tenant::factory()->create();
    $service = app(MessageService::class);
    $data = inbound_data('wamid-dedupe');

    $first = $service->handleInboundMessage($tenant, $data);
    $second = $service->handleInboundMessage($tenant, $data);

    expect($first->created)->toBeTrue()
        ->and($second->created)->toBeFalse()
        ->and($first->message->id)->toBe($second->message->id)
        ->and(Message::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('MSG-3: CRITICO — el mismo id de mensaje en dos tenants aísla contact, conversation y message', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $service = app(MessageService::class);
    $data = inbound_data('wamid-shared');

    $messageA = $service->handleInboundMessage($tenantA, $data)->message;
    $messageB = $service->handleInboundMessage($tenantB, $data)->message;

    expect($messageA->id)->not->toBe($messageB->id);

    expect(Message::query()->withoutTenantScope()->where('tenant_id', $tenantA->id)->count())->toBe(1)
        ->and(Message::query()->withoutTenantScope()->where('tenant_id', $tenantB->id)->count())->toBe(1)
        ->and(Contact::query()->withoutTenantScope()->where('tenant_id', $tenantA->id)->count())->toBe(1)
        ->and(Contact::query()->withoutTenantScope()->where('tenant_id', $tenantB->id)->count())->toBe(1)
        ->and(Conversation::query()->withoutTenantScope()->where('tenant_id', $tenantA->id)->count())->toBe(1)
        ->and(Conversation::query()->withoutTenantScope()->where('tenant_id', $tenantB->id)->count())->toBe(1);
});

test('MSG-4: un tipo de Meta no soportado marca el evento failed y NO persiste mensaje', function (): void {
    config(['whatsapp.app_secret' => whatsapp_secret()]);

    $tenant = Tenant::factory()->create();
    make_whatsapp_setup($tenant);

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
                    'messages' => [[
                        'from' => '15550000001',
                        'id' => 'msg-reaction',
                        'timestamp' => '1725000000',
                        'type' => 'reaction',
                        'reaction' => ['message_id' => 'wamid-x', 'emoji' => '🔥'],
                    ]],
                ],
            ]],
        ]],
    ];

    post_whatsapp_webhook(json_encode($payload, JSON_THROW_ON_ERROR))->assertOk();

    $event = WebhookEvent::query()->where('provider_event_id', 'msg-reaction')->firstOrFail();

    expect($event->status->value)->toBe('failed')
        ->and($event->error_code)->toBe('unsupported_message_type');

    expect(Message::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('MSG-5: un mensaje de imagen persiste caption, mime, size y metadata media', function (): void {
    $tenant = Tenant::factory()->create();
    $service = app(MessageService::class);

    $data = [
        'id' => 'wamid-img',
        'from' => '15550000001',
        'timestamp' => '1725000000',
        'type' => 'image',
        'image' => [
            'id' => 'media-1',
            'mime_type' => 'image/jpeg',
            'sha256' => 'abc123',
            'caption' => 'Foto del producto',
            'size' => 1234,
        ],
    ];

    $message = $service->handleInboundMessage($tenant, $data)->message;

    expect($message->type)->toBe(MessageType::Image)
        ->and($message->body)->toBe('Foto del producto')
        ->and($message->media_mime)->toBe('image/jpeg')
        ->and($message->media_size)->toBe(1234)
        ->and($message->metadata['media']['sha256'])->toBe('abc123');
});

test('U3-MSG-01: media inbound normaliza metadata sin descargar binarios', function (): void {
    $tenant = Tenant::factory()->create();
    $service = app(MessageService::class);

    $cases = [
        [
            'type' => 'video',
            'video' => ['id' => 'media-video', 'mime_type' => 'video/mp4', 'sha256' => 'hash-video', 'caption' => 'Video', 'size' => 10],
            'expected_type' => MessageType::Video,
            'expected_body' => 'Video',
        ],
        [
            'type' => 'audio',
            'audio' => ['id' => 'media-audio', 'mime_type' => 'audio/ogg', 'voice' => true, 'size' => 20],
            'expected_type' => MessageType::Audio,
            'expected_body' => null,
        ],
        [
            'type' => 'document',
            'document' => ['id' => 'media-document', 'mime_type' => 'application/pdf', 'filename' => 'factura.pdf', 'caption' => 'Factura'],
            'expected_type' => MessageType::Document,
            'expected_body' => 'Factura',
        ],
    ];

    foreach ($cases as $index => $case) {
        $data = array_merge([
            'id' => 'wamid-media-'.$index,
            'from' => '15550000001',
            'timestamp' => '1725000000',
        ], $case);

        $message = $service->handleInboundMessage($tenant, $data)->message;

        expect($message)->not->toBeNull()
            ->and($message->type)->toBe($case['expected_type'])
            ->and($message->body)->toBe($case['expected_body'])
            ->and($message->metadata['media']['id'])->toBe($case[$case['type']]['id']);
    }
});

test('U3-MSG-02: interactive button/list y location se normalizan de forma determinista', function (): void {
    $tenant = Tenant::factory()->create();
    $service = app(MessageService::class);

    $button = $service->handleInboundMessage($tenant, [
        'id' => 'wamid-button',
        'from' => '15550000001',
        'timestamp' => '1725000000',
        'type' => 'interactive',
        'interactive' => [
            'type' => 'button',
            'button_reply' => ['id' => 'button-id', 'title' => 'Sí'],
        ],
    ])->message;
    $list = $service->handleInboundMessage($tenant, [
        'id' => 'wamid-list',
        'from' => '15550000001',
        'timestamp' => '1725000001',
        'type' => 'interactive',
        'interactive' => [
            'type' => 'list',
            'list_reply' => ['id' => 'list-id', 'title' => 'Opción'],
        ],
    ])->message;
    $location = $service->handleInboundMessage($tenant, [
        'id' => 'wamid-location',
        'from' => '15550000001',
        'timestamp' => '1725000002',
        'type' => 'location',
        'location' => [
            'latitude' => '19.4326',
            'longitude' => '-99.1332',
            'name' => 'Centro',
            'address' => 'Avenida Principal',
        ],
    ])->message;

    expect($button->body)->toBe('Sí')
        ->and($button->metadata['interactive'])->toBe(['type' => 'button', 'id' => 'button-id', 'title' => 'Sí'])
        ->and($list->body)->toBe('Opción')
        ->and($list->metadata['interactive'])->toBe(['type' => 'list', 'id' => 'list-id', 'title' => 'Opción'])
        ->and($location->metadata['location'])->toBe([
            'latitude' => 19.4326,
            'longitude' => -99.1332,
            'name' => 'Centro',
            'address' => 'Avenida Principal',
        ]);
});

test('U3-MSG-03: inbound soportado malformado no persiste mensaje', function (): void {
    $tenant = Tenant::factory()->create();
    $service = app(MessageService::class);

    expect($service->handleInboundMessage($tenant, [
        'id' => 'wamid-invalid-text',
        'from' => '15550000001',
        'timestamp' => '1725000000',
        'type' => 'text',
        'text' => ['body' => ['not' => 'scalar']],
    ])->message)->toBeNull();

    expect(Message::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('MSG-6: un mensaje sin id o sin from no persiste nada (no-op)', function (): void {
    $tenant = Tenant::factory()->create();
    $service = app(MessageService::class);

    expect($service->handleInboundMessage($tenant, ['type' => 'text', 'text' => ['body' => 'x']])->message)->toBeNull()
        ->and($service->handleInboundMessage($tenant, ['id' => 'wamid-x', 'type' => 'text'])->message)->toBeNull();

    expect(Message::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('MSG-7: un mensaje entrante reabre una conversación resuelta y actualiza timestamps', function (): void {
    $tenant = Tenant::factory()->create();
    $contact = make_contact($tenant, ['phone' => '+15550000001']);
    $conversation = make_conversation($tenant, $contact, ['status' => 'resolved']);

    app(MessageService::class)->handleInboundMessage($tenant, inbound_data('wamid-reopen'));

    $conversation->refresh();

    expect($conversation->status)->toBe(ConversationStatus::Open)
        ->and($conversation->last_message_at)->not->toBeNull()
        ->and($conversation->last_interaction_at)->not->toBeNull()
        ->and(Conversation::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('MSG-8: mensajes del mismo contacto reutilizan la conversación activa', function (): void {
    $tenant = Tenant::factory()->create();
    $service = app(MessageService::class);

    $service->handleInboundMessage($tenant, inbound_data('wamid-a', 'Uno'));
    $service->handleInboundMessage($tenant, inbound_data('wamid-b', 'Dos'));

    expect(Conversation::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(1)
        ->and(Message::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(2);
});

test('MSG-9: el inbound audita message.received con el tenant correcto', function (): void {
    $tenant = Tenant::factory()->create();

    app(MessageService::class)->handleInboundMessage($tenant, inbound_data('wamid-audit'));

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'message.received',
        'tenant_id' => $tenant->id,
    ]);
});
