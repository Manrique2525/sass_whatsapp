<?php

declare(strict_types=1);

use App\Application\Conversations\Services\ConversationService;
use App\Domain\Billing\Contracts\CapacityGuardInterface;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Messages\Enums\MessageDirection;
use App\Domain\Messages\Enums\MessageStatus;
use App\Domain\Messages\Enums\MessageType;
use App\Domain\Messages\Models\Message;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\TenantPermission;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Models\User;
use App\Events\ConversationUpdated;
use App\Events\MessageCreated;
use App\Events\MessageStatusUpdated;
use App\Infrastructure\Tenancy\TenantContext;
use App\Jobs\SendWhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeCapacityGuard;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(CapacityGuardInterface::class, new FakeCapacityGuard);
});

/*
|--------------------------------------------------------------------------
| FASE 10 — MENSAJES: REST API (historial + envío) y tiempo real
|--------------------------------------------------------------------------
*/

function message_url(Tenant $tenant, string $conversationId): string
{
    return '/api/v1/tenants/'.$tenant->id.'/conversations/'.$conversationId.'/messages';
}

function make_message(Tenant $tenant, Conversation $conversation, array $attributes = []): Message
{
    TenantContext::setId($tenant->id);

    try {
        return Message::query()->create(array_merge([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'type' => 'text',
            'status' => 'delivered',
            'body' => 'Hola',
            'delivered_at' => now(),
        ], $attributes));
    } finally {
        TenantContext::clear();
    }
}

test('MSG-API-1: index pagina el historial DESC y expone el recurso completo', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $contact = make_contact($tenant);
    $conversation = make_conversation($tenant, $contact);

    $older = make_message($tenant, $conversation, ['body' => 'Primero']);
    $newer = make_message($tenant, $conversation, ['body' => 'Segundo']);

    $this->actingAs($owner)
        ->getJson(message_url($tenant, $conversation->id))
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonCount(2, 'messages')
        ->assertJsonPath('messages.0.id', $newer->id)
        ->assertJsonPath('messages.1.id', $older->id)
        ->assertJsonPath('messages.0.direction', 'inbound')
        ->assertJsonPath('messages.0.type', 'text')
        ->assertJsonPath('messages.0.status', 'delivered')
        ->assertJsonPath('messages.0.body', 'Segundo')
        ->assertJsonPath('messages.0.conversation_id', $conversation->id)
        ->assertJsonPath('messages.0.metadata', null);
});

test('MSG-API-2: un agente puede listar el historial (conversations.view)', function (): void {
    $tenant = Tenant::factory()->create();
    $agent = User::factory()->create();
    make_tenant_member($agent, $tenant, 'agent');

    $contact = make_contact($tenant);
    $conversation = make_conversation($tenant, $contact);
    make_message($tenant, $conversation);

    $this->actingAs($agent)
        ->getJson(message_url($tenant, $conversation->id))
        ->assertOk()
        ->assertJsonPath('meta.total', 1);
});

test('MSG-API-3: index respeta per_page acotado a 100 y pagina', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $contact = make_contact($tenant);
    $conversation = make_conversation($tenant, $contact);

    for ($i = 0; $i < 5; $i++) {
        make_message($tenant, $conversation, ['body' => 'Mensaje '.$i]);
    }

    $this->actingAs($owner)
        ->getJson(message_url($tenant, $conversation->id).'?per_page=2&page=2')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.last_page', 3)
        ->assertJsonCount(2, 'messages');

    $this->actingAs($owner)
        ->getJson(message_url($tenant, $conversation->id).'?per_page=500')
        ->assertStatus(422)
        ->assertJsonValidationErrors('per_page');
});

test('MSG-API-4: CRITICO — un usuario de A jamás lista mensajes de una conversación de B (404)', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $userA = User::factory()->create();
    make_tenant_member($userA, $tenantA, 'owner');

    $contactB = make_contact($tenantB);
    $conversationB = make_conversation($tenantB, $contactB);
    make_message($tenantB, $conversationB);

    $this->actingAs($userA)
        ->getJson(message_url($tenantB, $conversationB->id))
        ->assertStatus(404);
});

test('MSG-API-5: una conversación inexistente en el tenant devuelve 404 (oculta existencia)', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $this->actingAs($owner)
        ->getJson(message_url($tenant, 'no-existe'))
        ->assertStatus(404);
});

test('MSG-API-6: POST envía un mensaje de texto: pending + job encolado + timestamps', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $contact = make_contact($tenant);
    $conversation = make_conversation($tenant, $contact);

    $this->actingAs($owner)
        ->postJson(message_url($tenant, $conversation->id), ['body' => 'Hola, ¿en qué te ayudo?'])
        ->assertStatus(201)
        ->assertJsonPath('message', 'Mensaje encolado para envío.')
        ->assertJsonPath('created_message.direction', 'outbound')
        ->assertJsonPath('created_message.type', 'text')
        ->assertJsonPath('created_message.status', 'pending')
        ->assertJsonPath('created_message.body', 'Hola, ¿en qué te ayudo?');

    $message = Message::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();

    expect($message->direction)->toBe(MessageDirection::Outbound)
        ->and($message->type)->toBe(MessageType::Text)
        ->and($message->status)->toBe(MessageStatus::Pending)
        ->and($message->sent_by_user_id)->toBe($owner->id)
        ->and($message->metadata['origin'])->toBe('human');

    Queue::assertPushed(SendWhatsAppMessage::class, function (SendWhatsAppMessage $job) use ($tenant, $conversation, $message): bool {
        return $job->tenantId === $tenant->id
            && $job->conversationId === $conversation->id
            && $job->messageId === $message->id;
    });

    $conversation->refresh();

    expect($conversation->last_message_at)->not->toBeNull()
        ->and($conversation->last_interaction_at)->not->toBeNull();
});

test('MSG-API-7: POST valida body (required, string, no vacío solo espacios)', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $contact = make_contact($tenant);
    $conversation = make_conversation($tenant, $contact);

    $this->actingAs($owner)
        ->postJson(message_url($tenant, $conversation->id), [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('body');

    $this->actingAs($owner)
        ->postJson(message_url($tenant, $conversation->id), ['body' => str_repeat('a', 5000)])
        ->assertStatus(422)
        ->assertJsonValidationErrors('body');
});

test('MSG-API-8: CRITICO — un agente del tenant A no puede enviar a una conversación de B (404)', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $agentA = User::factory()->create();
    make_tenant_member($agentA, $tenantA, 'agent');

    $contactB = make_contact($tenantB);
    $conversationB = make_conversation($tenantB, $contactB);

    $this->actingAs($agentA)
        ->postJson(message_url($tenantB, $conversationB->id), ['body' => 'Hola'])
        ->assertStatus(404);

    $this->assertDatabaseMissing('messages', [
        'tenant_id' => $tenantB->id,
        'conversation_id' => $conversationB->id,
    ]);
});

test('MSG-API-9: un agente puede responder desde el inbox (messages.send)', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    $agent = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');
    make_tenant_member($agent, $tenant, 'agent');

    $contact = make_contact($tenant);
    $conversation = make_conversation($tenant, $contact);
    TenantContext::withId($tenant->id, fn () => app(ConversationService::class)
        ->assign($owner, $tenant, $conversation->id, $agent->id));

    $this->actingAs($agent)
        ->postJson(message_url($tenant, $conversation->id), ['body' => 'Respuesta del agente'])
        ->assertStatus(201)
        ->assertJsonPath('created_message.direction', 'outbound')
        ->assertJsonPath('created_message.sent_by_user_id', $agent->id)
        ->assertJsonPath('created_message.metadata.origin', 'human');
});

test('MSG-API-10: todos los roles del tenant tienen messages.send (matriz)', function (): void {
    foreach ([UserRole::Owner, UserRole::Admin, UserRole::Agent] as $role) {
        expect(in_array(TenantPermission::SendMessages, TenantPermission::permissionsForRole($role), true))->toBeTrue();
    }
});

test('MSG-API-11: un usuario de otro tenant recibe 404 (no expone el recurso)', function (): void {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $stranger = User::factory()->create();
    make_tenant_member($stranger, $other, 'owner');

    $contact = make_contact($tenant);
    $conversation = make_conversation($tenant, $contact);

    $this->actingAs($stranger)
        ->getJson(message_url($tenant, $conversation->id))
        ->assertStatus(404);

    $this->actingAs($stranger)
        ->postJson(message_url($tenant, $conversation->id), ['body' => 'Hola'])
        ->assertStatus(404);
});

test('MSG-API-12: enviar un mensaje emite MessageCreated en el canal privado de la conversación', function (): void {
    Event::fake([MessageCreated::class]);
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $contact = make_contact($tenant);
    $conversation = make_conversation($tenant, $contact);

    $this->actingAs($owner)
        ->postJson(message_url($tenant, $conversation->id), ['body' => 'Hola'])
        ->assertStatus(201);

    Event::assertDispatched(MessageCreated::class, function (MessageCreated $event) use ($tenant, $conversation): bool {
        $channel = $event->broadcastOn()[0];

        return $event->message->tenant_id === $tenant->id
            && $event->message->conversation_id === $conversation->id
            && $event->broadcastAs() === 'MessageCreated'
            && $channel->name === 'private-tenant.'.$tenant->id.'.conversations.'.$conversation->id;
    });
});

test('MSG-API-13: el webhook inbound emite MessageCreated y ConversationUpdated (realtime)', function (): void {
    Event::fake([MessageCreated::class, ConversationUpdated::class]);
    config(['whatsapp.app_secret' => whatsapp_secret()]);

    $tenant = Tenant::factory()->create();
    make_whatsapp_setup($tenant);

    post_whatsapp_webhook(whatsapp_webhook_payload('wamid-realtime-1', 'phone-1'));

    $conversation = Conversation::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();

    Event::assertDispatched(MessageCreated::class, fn (MessageCreated $event): bool => $event->message->body === 'Hola');

    Event::assertDispatched(ConversationUpdated::class, fn (ConversationUpdated $event): bool => $event->conversation->id === $conversation->id);
});

test('MSG-API-14: un status update emite MessageStatusUpdated', function (): void {
    Event::fake([MessageStatusUpdated::class]);
    config(['whatsapp.app_secret' => whatsapp_secret()]);

    $tenant = Tenant::factory()->create();
    make_whatsapp_setup($tenant);

    post_whatsapp_webhook(whatsapp_webhook_payload('wamid-status-1', 'phone-1'));

    $message = Message::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();

    post_whatsapp_webhook(json_encode([
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => '104000000000000',
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'metadata' => ['display_phone_number' => '15550000002', 'phone_number_id' => 'phone-1'],
                    'statuses' => [[
                        'id' => 'wamid-status-1',
                        'recipient_id' => '15550000002',
                        'status' => 'read',
                        'timestamp' => '1725000002',
                    ]],
                ],
            ]],
        ]],
    ], JSON_THROW_ON_ERROR));

    $message->refresh();

    expect($message->status)->toBe(MessageStatus::Read);

    Event::assertDispatched(MessageStatusUpdated::class, function (MessageStatusUpdated $event) use ($message): bool {
        return $event->message->id === $message->id
            && $event->message->status === MessageStatus::Read
            && $event->previousStatus === MessageStatus::Delivered->value;
    });
});

test('MSG-API-15: cerrar una conversación emite ConversationUpdated', function (): void {
    Event::fake([ConversationUpdated::class]);

    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $contact = make_contact($tenant);
    $conversation = make_conversation($tenant, $contact);

    $this->actingAs($owner)
        ->postJson('/api/v1/tenants/'.$tenant->id.'/conversations/'.$conversation->id.'/close')
        ->assertOk();

    Event::assertDispatched(ConversationUpdated::class, fn (ConversationUpdated $event): bool => $event->conversation->id === $conversation->id
        && $event->conversation->status->value === 'resolved');
});

test('MSG-API-16: la lista de conversaciones incluye last_message (preview del chat)', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $contact = make_contact($tenant);
    $conversation = make_conversation($tenant, $contact);

    make_message($tenant, $conversation, ['body' => 'Primer mensaje']);
    $latest = make_message($tenant, $conversation, ['body' => 'Último mensaje', 'direction' => 'outbound', 'status' => 'sent']);

    $this->actingAs($owner)
        ->getJson('/api/v1/tenants/'.$tenant->id.'/conversations')
        ->assertOk()
        ->assertJsonPath('conversations.0.last_message.id', $latest->id)
        ->assertJsonPath('conversations.0.last_message.body', 'Último mensaje')
        ->assertJsonPath('conversations.0.last_message.direction', 'outbound');
});

test('MSG-API-17: un agent sin assignment vigente no puede responder', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $agent = User::factory()->create();
    make_tenant_member($agent, $tenant, 'agent');
    $conversation = make_conversation($tenant, make_contact($tenant));

    $this->actingAs($agent)
        ->postJson(message_url($tenant, $conversation->id), ['body' => 'No autorizada'])
        ->assertStatus(403)
        ->assertJsonPath('code', 'CONVERSATION_REPLY_FORBIDDEN');

    expect(Message::withoutTenantScope()->where('conversation_id', $conversation->id)->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('MSG-API-18: owner puede responder sin assignment como override administrativo', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');
    $conversation = make_conversation($tenant, make_contact($tenant));

    $this->actingAs($owner)
        ->postJson(message_url($tenant, $conversation->id), ['body' => 'Respuesta owner'])
        ->assertCreated()
        ->assertJsonPath('created_message.sent_by_user_id', $owner->id);
});

test('MSG-API-19: responder una conversación cerrada devuelve conflicto sin encolar', function (string $status): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');
    $conversation = make_conversation($tenant, make_contact($tenant), ['status' => $status]);

    $this->actingAs($owner)
        ->postJson(message_url($tenant, $conversation->id), ['body' => 'No debe salir'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'CONVERSATION_INVALID_STATE');

    expect(Message::withoutTenantScope()->where('conversation_id', $conversation->id)->count())->toBe(0);
    Queue::assertNothingPushed();
})->with(['resolved', 'archived']);

test('MSG-API-20: actor origen tenant y estado aportados por frontend están prohibidos', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');
    $conversation = make_conversation($tenant, make_contact($tenant));

    $this->actingAs($owner)
        ->postJson(message_url($tenant, $conversation->id), [
            'body' => 'Intento manipulado',
            'tenant_id' => 'otro',
            'sent_by_user_id' => 999,
            'metadata' => ['origin' => 'handoff'],
            'origin' => 'automation',
            'direction' => 'inbound',
            'status' => 'sent',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors([
            'tenant_id',
            'sent_by_user_id',
            'metadata',
            'origin',
            'direction',
            'status',
        ]);

    expect(Message::withoutTenantScope()->where('conversation_id', $conversation->id)->count())->toBe(0);
    Queue::assertNothingPushed();
});
