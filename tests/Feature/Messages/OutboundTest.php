<?php

declare(strict_types=1);

use App\Application\Messages\Services\MessageService;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Messages\Enums\MessageDirection;
use App\Domain\Messages\Enums\MessageStatus;
use App\Domain\Messages\Enums\MessageType;
use App\Domain\Messages\Models\Message;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\WhatsApp\Exceptions\WhatsAppMessageFailedException;
use App\Domain\WhatsApp\Models\MessageSendAttempt;
use App\Infrastructure\Tenancy\TenantContext;
use App\Jobs\SendWhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| FASE 9 — MENSAJES: OUTBOUND (createOutbound + SendWhatsAppMessage)
|--------------------------------------------------------------------------
*/

function make_ready_conversation(Tenant $tenant): Conversation
{
    $contact = make_contact($tenant, ['phone' => '+15550000001']);

    return make_conversation($tenant, $contact);
}

function fake_graph_send_success(string $providerMessageId = 'wamid-456'): void
{
    Http::fake([
        'graph.facebook.com/*/phone-1/messages' => Http::response([
            'messaging_product' => 'whatsapp',
            'contacts' => [['input' => '15550000001', 'wa_id' => '15550000001']],
            'messages' => [['id' => $providerMessageId]],
        ], 200),
    ]);
}

test('OUT-1: createOutbound persiste el mensaje pending y encola SendWhatsAppMessage', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $conversation = make_ready_conversation($tenant);

    $message = app(MessageService::class)->createOutbound($tenant, $conversation, 'Hola cliente');

    expect($message->direction)->toBe(MessageDirection::Outbound)
        ->and($message->type)->toBe(MessageType::Text)
        ->and($message->status)->toBe(MessageStatus::Pending)
        ->and($message->body)->toBe('Hola cliente');

    Queue::assertPushed(SendWhatsAppMessage::class, function (SendWhatsAppMessage $job) use ($tenant, $conversation, $message): bool {
        return $job->tenantId === $tenant->id
            && $job->messageId === $message->id
            && $job->conversationId === $conversation->id;
    });

    $this->assertDatabaseHas('messages', [
        'id' => $message->id,
        'tenant_id' => $tenant->id,
        'status' => 'pending',
        'direction' => 'outbound',
    ]);
});

test('OUT-2: SendWhatsAppMessage envía, persiste provider_message_id y audita', function (): void {
    fake_graph_send_success();

    $tenant = Tenant::factory()->create();
    make_whatsapp_setup($tenant);
    $conversation = make_ready_conversation($tenant);

    app(MessageService::class)->createOutbound($tenant, $conversation, 'Hola cliente');

    $message = Message::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();

    expect($message->status)->toBe(MessageStatus::Sent)
        ->and($message->provider_message_id)->toBe('wamid-456')
        ->and($message->sent_at)->not->toBeNull();

    $this->assertDatabaseHas('message_send_attempts', [
        'tenant_id' => $tenant->id,
        'to' => '+15550000001',
        'status' => 'sent',
        'provider_message_id' => 'wamid-456',
        'attempt' => 1,
        'max_attempts' => 3,
    ]);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'message.sent',
        'tenant_id' => $tenant->id,
        'subject_id' => $message->id,
    ]);
});

test('OUT-3: un error permanente de Meta marca el mensaje failed sin reintentar', function (): void {
    Http::fake([
        'graph.facebook.com/*/phone-1/messages' => Http::response([
            'error' => [
                'message' => '(#131030) Recipient phone number not in allowed list.',
                'type' => 'OAuthException',
                'code' => 131030,
            ],
        ], 400),
    ]);

    $tenant = Tenant::factory()->create();
    make_whatsapp_setup($tenant);
    $conversation = make_ready_conversation($tenant);

    app(MessageService::class)->createOutbound($tenant, $conversation, 'Hola cliente');

    $message = Message::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();

    expect($message->status)->toBe(MessageStatus::Failed)
        ->and($message->failed_at)->not->toBeNull();

    $attempt = MessageSendAttempt::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();

    expect($attempt->status->value)->toBe('failed')
        ->and($attempt->error_code)->toBe('WHATSAPP_MESSAGE_FAILED')
        ->and($attempt->payload['retryable'])->toBeFalse();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'message.failed',
        'tenant_id' => $tenant->id,
        'subject_id' => $message->id,
    ]);
});

test('OUT-4: un error retryable relanza el job y deja el mensaje en sending', function (): void {
    Http::fake([
        'graph.facebook.com/*/phone-1/messages' => Http::response([
            'error' => ['message' => 'Upstream error.', 'code' => 2],
        ], 500),
    ]);

    $tenant = Tenant::factory()->create();
    make_whatsapp_setup($tenant);
    $conversation = make_ready_conversation($tenant);

    $this->expectException(WhatsAppMessageFailedException::class);
    app(MessageService::class)->createOutbound($tenant, $conversation, 'Hola cliente');

    $message = Message::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();

    expect($message->status)->toBe(MessageStatus::Sending);

    $attempt = MessageSendAttempt::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();

    expect($attempt->status->value)->toBe('failed')
        ->and($attempt->payload['retryable'])->toBeTrue();
});

test('OUT-5: el CAS evita reenviar un mensaje ya enviado', function (): void {
    fake_graph_send_success('wamid-ya-enviado');

    $tenant = Tenant::factory()->create();
    make_whatsapp_setup($tenant);
    $conversation = make_ready_conversation($tenant);

    TenantContext::setId($tenant->id);

    try {
        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'type' => 'text',
            'status' => 'sent',
            'body' => 'Ya enviado',
            'provider_message_id' => 'wamid-ya-enviado',
            'sent_at' => now(),
        ]);
    } finally {
        TenantContext::clear();
    }

    (new SendWhatsAppMessage($tenant->id, $conversation->id, $message->id))->handle();

    expect(MessageSendAttempt::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('OUT-6: sin cuenta conectada el mensaje falla con whatsapp_not_connected', function (): void {
    $tenant = Tenant::factory()->create();
    $conversation = make_ready_conversation($tenant);

    TenantContext::setId($tenant->id);

    try {
        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'type' => 'text',
            'status' => 'pending',
            'body' => 'Hola',
        ]);
    } finally {
        TenantContext::clear();
    }

    (new SendWhatsAppMessage($tenant->id, $conversation->id, $message->id))->handle();

    $message->refresh();

    expect($message->status)->toBe(MessageStatus::Failed);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'message.failed',
        'tenant_id' => $tenant->id,
        'subject_id' => $message->id,
    ]);
});

test('OUT-7: un tipo no soportado en outbound falla con unsupported_outbound_type', function (): void {
    $tenant = Tenant::factory()->create();
    make_whatsapp_setup($tenant);
    $conversation = make_ready_conversation($tenant);

    TenantContext::setId($tenant->id);

    try {
        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'type' => 'interactive',
            'status' => 'pending',
            'metadata' => ['interactive' => ['type' => 'button']],
        ]);
    } finally {
        TenantContext::clear();
    }

    (new SendWhatsAppMessage($tenant->id, $conversation->id, $message->id))->handle();

    $message->refresh();

    expect($message->status)->toBe(MessageStatus::Failed)
        ->and($message->failed_at)->not->toBeNull();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'message.failed',
        'tenant_id' => $tenant->id,
        'subject_id' => $message->id,
    ]);
});
