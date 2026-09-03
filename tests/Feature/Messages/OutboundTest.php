<?php

declare(strict_types=1);

use App\Application\Audit\Services\AuditLogger;
use App\Application\Messages\Services\MessageService;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Messages\Enums\MessageDirection;
use App\Domain\Messages\Enums\MessageOrigin;
use App\Domain\Messages\Enums\MessageStatus;
use App\Domain\Messages\Enums\MessageType;
use App\Domain\Messages\Models\Message;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Domain\WhatsApp\Exceptions\WhatsAppMessageFailedException;
use App\Domain\WhatsApp\Models\MessageSendAttempt;
use App\Infrastructure\Tenancy\TenantContext;
use App\Jobs\SendWhatsAppMessage;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

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

test('OUT-U4-01: una pérdida de transporte deja el mensaje ambiguo y no lo reenvía', function (): void {
    Http::fake(function (): never {
        throw new ConnectionException('Connection timed out.');
    });

    $tenant = Tenant::factory()->create();
    make_whatsapp_setup($tenant);
    $conversation = make_ready_conversation($tenant);

    app(MessageService::class)->createOutbound($tenant, $conversation, 'Hola cliente');

    $message = Message::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();
    $attempt = MessageSendAttempt::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();

    expect($message->status)->toBe(MessageStatus::Sending)
        ->and($message->metadata['delivery_state'])->toBe('ambiguous')
        ->and($attempt->payload['classification'])->toBe('ambiguous')
        ->and(MessageSendAttempt::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(1);

    (new SendWhatsAppMessage($tenant->id, $conversation->id, $message->id))->handle();

    expect(MessageSendAttempt::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(1);
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

test('HANDOFF-OUT-01: un outbound automático pendiente se bloquea tras handoff sin llamar a Meta', function (): void {
    Queue::fake();
    Http::fake();

    $tenant = Tenant::factory()->create();
    make_whatsapp_setup($tenant);
    $conversation = make_ready_conversation($tenant);
    $message = app(MessageService::class)->createOutbound($tenant, $conversation, 'Respuesta automática');
    $conversation->forceFill([
        'bot_paused' => true,
        'handoff_requested_at' => now(),
    ])->save();

    (new SendWhatsAppMessage($tenant->id, $conversation->id, $message->id))->handle();

    $message->refresh();

    expect($message->status)->toBe(MessageStatus::Failed)
        ->and($message->failed_at)->not->toBeNull()
        ->and($message->metadata['origin'])->toBe(MessageOrigin::Automation->value)
        ->and($message->metadata['error_code'])->toBe('BOT_PAUSED_HANDOFF')
        ->and($message->metadata['error_source'])->toBe('internal')
        ->and(MessageSendAttempt::withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(0);

    $audit = AuditLog::query()
        ->where('tenant_id', $tenant->id)
        ->where('subject_id', $message->id)
        ->where('action', 'message.failed')
        ->firstOrFail();

    expect($audit->data['error_code'])->toBe('BOT_PAUSED_HANDOFF');
    Http::assertNothingSent();
});

test('HANDOFF-OUT-02: un outbound legacy sin actor se bloquea fail-closed tras handoff', function (): void {
    Http::fake();

    $tenant = Tenant::factory()->create();
    make_whatsapp_setup($tenant);
    $conversation = make_ready_conversation($tenant);

    $message = TenantContext::withId($tenant->id, fn (): Message => Message::query()->create([
        'conversation_id' => $conversation->id,
        'direction' => MessageDirection::Outbound,
        'type' => MessageType::Text,
        'status' => MessageStatus::Pending,
        'body' => 'Legacy',
        'metadata' => ['text' => 'Legacy'],
    ]));
    $conversation->forceFill([
        'bot_paused' => true,
        'handoff_requested_at' => now(),
    ])->save();

    (new SendWhatsAppMessage($tenant->id, $conversation->id, $message->id))->handle();

    expect($message->fresh()->status)->toBe(MessageStatus::Failed)
        ->and($message->fresh()->metadata['error_code'])->toBe('BOT_PAUSED_HANDOFF')
        ->and(MessageSendAttempt::withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(0);
    Http::assertNothingSent();
});

test('HANDOFF-OUT-03: mensajes human y handoff pueden enviarse con bot pausado', function (): void {
    Queue::fake();
    $sent = 0;
    Http::fake(function () use (&$sent) {
        $sent++;

        return Http::response([
            'messaging_product' => 'whatsapp',
            'contacts' => [['input' => '15550000001', 'wa_id' => '15550000001']],
            'messages' => [['id' => 'wamid-handoff-'.$sent]],
        ], 200);
    });

    $tenant = Tenant::factory()->create();
    make_whatsapp_setup($tenant);
    $user = User::factory()->create();
    make_tenant_member($user, $tenant, 'owner');
    $conversation = make_ready_conversation($tenant);
    $human = app(MessageService::class)->createOutbound(
        $tenant,
        $conversation,
        'Respuesta humana',
        MessageOrigin::Human,
        $user,
    );
    $executionId = (string) Str::uuid();
    app(AuditLogger::class)->record(
        action: 'flow.handoff',
        data: ['flow_execution_id' => $executionId],
        subjectType: Conversation::class,
        subjectId: $conversation->id,
        tenantId: $tenant->id,
    );
    $notice = app(MessageService::class)->createOutbound(
        $tenant,
        $conversation,
        'Aviso de handoff',
        MessageOrigin::Handoff,
        metadata: ['flow_execution_id' => $executionId],
    );
    $conversation->forceFill([
        'bot_paused' => true,
        'handoff_requested_at' => now(),
    ])->save();

    (new SendWhatsAppMessage($tenant->id, $conversation->id, $human->id))->handle();
    (new SendWhatsAppMessage($tenant->id, $conversation->id, $notice->id))->handle();

    expect($human->fresh()->status)->toBe(MessageStatus::Sent)
        ->and($human->fresh()->sent_by_user_id)->toBe($user->id)
        ->and($notice->fresh()->status)->toBe(MessageStatus::Sent)
        ->and($notice->fresh()->sent_by_user_id)->toBeNull()
        ->and(MessageSendAttempt::withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(2);
    Http::assertSentCount(2);
});

test('HANDOFF-OUT-04: combinaciones origin actor corruptas se bloquean fail-closed', function (): void {
    Http::fake();

    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    make_tenant_member($user, $tenant, 'owner');
    $conversation = make_ready_conversation($tenant);
    $conversation->forceFill([
        'bot_paused' => true,
        'handoff_requested_at' => now(),
    ])->save();

    $messages = TenantContext::withId($tenant->id, function () use ($conversation, $user): array {
        $humanWithoutActor = Message::query()->create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Outbound,
            'type' => MessageType::Text,
            'status' => MessageStatus::Pending,
            'body' => 'Human corrupto',
            'metadata' => ['origin' => MessageOrigin::Human->value],
        ]);
        $handoffWithoutAudit = Message::query()->create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Outbound,
            'type' => MessageType::Text,
            'status' => MessageStatus::Pending,
            'body' => 'Handoff corrupto',
            'metadata' => [
                'origin' => MessageOrigin::Handoff->value,
                'flow_execution_id' => (string) Str::uuid(),
            ],
        ]);
        $unknownWithActor = Message::query()->create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Outbound,
            'type' => MessageType::Text,
            'status' => MessageStatus::Pending,
            'body' => 'Origen desconocido',
            'metadata' => ['origin' => 'unknown'],
        ]);
        $unknownWithActor->forceFill(['sent_by_user_id' => $user->id])->save();

        return [$humanWithoutActor, $handoffWithoutAudit, $unknownWithActor];
    });

    foreach ($messages as $message) {
        (new SendWhatsAppMessage($tenant->id, $conversation->id, $message->id))->handle();
        expect($message->fresh()->status)->toBe(MessageStatus::Failed)
            ->and($message->fresh()->metadata['error_code'])->toBe('BOT_PAUSED_HANDOFF');
    }

    expect(MessageSendAttempt::withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(0);
    Http::assertNothingSent();
});

test('HANDOFF-OUT-05: contención sync y agotamiento async terminalizan sin intento Meta', function (): void {
    Http::fake();

    $tenant = Tenant::factory()->create();
    $conversation = make_ready_conversation($tenant);
    $message = TenantContext::withId($tenant->id, fn (): Message => Message::query()->create([
        'conversation_id' => $conversation->id,
        'direction' => MessageDirection::Outbound,
        'type' => MessageType::Text,
        'status' => MessageStatus::Pending,
        'body' => 'Pendiente por lock',
        'metadata' => ['origin' => MessageOrigin::Automation->value],
    ]));
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')->once()->andThrow(new LockTimeoutException);
    Cache::shouldReceive('lock')->once()->andReturn($lock);

    $job = new SendWhatsAppMessage($tenant->id, $conversation->id, $message->id);
    $job->handle();

    expect($message->fresh()->status)->toBe(MessageStatus::Failed)
        ->and($message->fresh()->metadata['error_code'])->toBe('MESSAGE_CONVERSATION_LOCK_TIMEOUT')
        ->and(MessageSendAttempt::withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(0)
        ->and($job->tries())->toBeGreaterThan((int) config('whatsapp.max_attempts'));

    $exhausted = TenantContext::withId($tenant->id, fn (): Message => Message::query()->create([
        'conversation_id' => $conversation->id,
        'direction' => MessageDirection::Outbound,
        'type' => MessageType::Text,
        'status' => MessageStatus::Pending,
        'body' => 'Agotado en queue',
        'metadata' => ['origin' => MessageOrigin::Automation->value],
    ]));
    $exhaustedJob = new SendWhatsAppMessage($tenant->id, $conversation->id, $exhausted->id);
    $exhaustedJob->failed(new RuntimeException('queue exhausted'));

    expect($exhausted->fresh()->status)->toBe(MessageStatus::Failed)
        ->and($exhausted->fresh()->metadata['error_code'])->toBe('MESSAGE_QUEUE_ATTEMPTS_EXHAUSTED');
    Http::assertNothingSent();
});

test('HANDOFF-OUT-06: intentos provider se numeran por llamada Meta y no por delivery del job', function (): void {
    Http::fakeSequence()
        ->push(['error' => ['message' => 'Upstream error.', 'code' => 2]], 500)
        ->push([
            'messaging_product' => 'whatsapp',
            'contacts' => [['input' => '15550000001', 'wa_id' => '15550000001']],
            'messages' => [['id' => 'wamid-second-attempt']],
        ], 200);

    $tenant = Tenant::factory()->create();
    make_whatsapp_setup($tenant);
    $conversation = make_ready_conversation($tenant);
    $message = TenantContext::withId($tenant->id, fn (): Message => Message::query()->create([
        'conversation_id' => $conversation->id,
        'direction' => MessageDirection::Outbound,
        'type' => MessageType::Text,
        'status' => MessageStatus::Pending,
        'body' => 'Retry real',
        'metadata' => ['origin' => MessageOrigin::Automation->value],
    ]));

    try {
        (new SendWhatsAppMessage($tenant->id, $conversation->id, $message->id))->handle();
    } catch (WhatsAppMessageFailedException) {
        // El segundo delivery representa el retry real de la cola.
    }

    (new SendWhatsAppMessage($tenant->id, $conversation->id, $message->id))->handle();

    $attempts = MessageSendAttempt::withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->orderBy('attempt')
        ->get();

    expect($message->fresh()->status)->toBe(MessageStatus::Sent)
        ->and($attempts->pluck('attempt')->all())->toBe([1, 2])
        ->and($attempts->pluck('max_attempts')->unique()->values()->all())->toBe([3]);
});

test('HANDOFF-OUT-07: crash entre CAS y attempt no hereda deliveries de lock como intentos Meta', function (): void {
    fake_graph_send_success('wamid-after-cas-crash');

    $tenant = Tenant::factory()->create();
    make_whatsapp_setup($tenant);
    $conversation = make_ready_conversation($tenant);
    $message = TenantContext::withId($tenant->id, fn (): Message => Message::query()->create([
        'conversation_id' => $conversation->id,
        'direction' => MessageDirection::Outbound,
        'type' => MessageType::Text,
        'status' => MessageStatus::Sending,
        'body' => 'Recuperado tras CAS',
        'metadata' => [
            'origin' => MessageOrigin::Automation->value,
            'attempt_tracking' => 'message_id_v1',
        ],
    ]));
    $queueJob = new FakeJob;
    $queueJob->attempts = 3;
    $job = new SendWhatsAppMessage($tenant->id, $conversation->id, $message->id);
    $job->setJob($queueJob);

    $job->handle();

    $attempt = MessageSendAttempt::withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->firstOrFail();

    expect($message->fresh()->status)->toBe(MessageStatus::Sent)
        ->and($attempt->attempt)->toBe(1)
        ->and($attempt->payload['message_id'])->toBe($message->id);
});
