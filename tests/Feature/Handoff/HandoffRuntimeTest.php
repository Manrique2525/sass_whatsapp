<?php

declare(strict_types=1);

use App\Application\Conversations\Services\ConversationService;
use App\Application\Conversations\Services\HumanHandoffService;
use App\Application\Flows\Services\FlowExecutionService;
use App\Application\Messages\Services\MessageService;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Conversations\Models\ConversationAssignment;
use App\Domain\Conversations\Models\ConversationParticipant;
use App\Domain\Flows\Enums\FlowExecutionStatus;
use App\Domain\Flows\Enums\FlowStatus;
use App\Domain\Flows\Enums\FlowTriggerType;
use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Models\FlowExecution;
use App\Domain\Flows\Models\FlowExecutionLog;
use App\Domain\Messages\Enums\MessageDirection;
use App\Domain\Messages\Enums\MessageOrigin;
use App\Domain\Messages\Enums\MessageStatus;
use App\Domain\Messages\Models\Message;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Domain\WhatsApp\Models\MessageSendAttempt;
use App\Events\ConversationUpdated;
use App\Events\MessageCreated;
use App\Infrastructure\Tenancy\TenantContext;
use App\Jobs\RecoverPendingWhatsAppMessage;
use App\Jobs\SendWhatsAppMessage;
use App\Jobs\StartFlowFromSchedule;
use App\Jobs\StartFlowFromWebhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $humanConfig
 */
function handoff_runtime_flow(Tenant $tenant, array $humanConfig = []): Flow
{
    $flow = make_flow($tenant, make_chatbot($tenant));

    make_flow_graph($flow, [
        [
            'id' => 'human',
            'type' => 'human',
            'name' => 'Atención humana',
            'config' => $humanConfig,
            'is_start' => true,
        ],
    ], []);

    $flow->forceFill(['status' => FlowStatus::Published])->save();
    make_trigger($flow, ['type' => FlowTriggerType::Start->value]);

    return $flow;
}

test('HANDOFF-RUNTIME-01: Human conserva open o pending y finaliza handed_off con mensaje previo', function (string $status): void {
    Queue::fake();
    Event::fake([ConversationUpdated::class, MessageCreated::class]);

    $tenant = Tenant::factory()->create();
    handoff_runtime_flow($tenant, ['handoff_message' => 'Te comunicaremos con una persona.']);

    $inbound = make_inbound_message($tenant, 'Necesito ayuda');
    $conversation = Conversation::withoutTenantScope()->findOrFail($inbound->conversation_id);
    $conversation->forceFill(['status' => $status])->save();

    run_flow_engine($tenant, $inbound, $conversation->fresh());

    $conversation->refresh();
    $execution = FlowExecution::withoutTenantScope()
        ->where('conversation_id', $conversation->id)
        ->firstOrFail();
    $notice = Message::withoutTenantScope()
        ->where('conversation_id', $conversation->id)
        ->where('direction', MessageDirection::Outbound->value)
        ->firstOrFail();

    expect($conversation->status->value)->toBe($status)
        ->and($conversation->bot_paused)->toBeTrue()
        ->and($conversation->handoff_requested_at)->not->toBeNull()
        ->and($conversation->flow_execution_id)->toBeNull()
        ->and($conversation->agent_id)->toBeNull()
        ->and($conversation->auto_assigned)->toBeFalse()
        ->and($execution->status)->toBe(FlowExecutionStatus::HandedOff)
        ->and($notice->body)->toBe('Te comunicaremos con una persona.')
        ->and($notice->metadata['origin'])->toBe(MessageOrigin::Handoff->value)
        ->and($notice->metadata['flow_execution_id'])->toBe($execution->id)
        ->and($notice->sent_by_user_id)->toBeNull();

    expect(FlowExecutionLog::withoutTenantScope()
        ->where('execution_id', $execution->id)
        ->where('event', 'execution.handed_off')
        ->count())->toBe(1);

    $this->assertDatabaseHas('audit_logs', [
        'tenant_id' => $tenant->id,
        'action' => 'flow.handoff',
        'subject_id' => $conversation->id,
    ]);
    $this->assertDatabaseHas('audit_logs', [
        'tenant_id' => $tenant->id,
        'action' => 'flow.execution_handed_off',
        'subject_id' => $execution->id,
    ]);

    Queue::assertPushed(SendWhatsAppMessage::class, fn (SendWhatsAppMessage $job): bool => $job->messageId === $notice->id);
    Event::assertDispatched(ConversationUpdated::class, fn (ConversationUpdated $event): bool => $event->conversation->id === $conversation->id
        && $event->conversation->flow_execution_id === null);
    Event::assertDispatched(MessageCreated::class, fn (MessageCreated $event): bool => $event->message->id === $notice->id);
})->with(['open', 'pending']);

test('HANDOFF-RUNTIME-02: handoff_message ausente o sin texto no crea outbound', function (array $config): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    handoff_runtime_flow($tenant, $config);

    $inbound = make_inbound_message($tenant, 'Necesito ayuda');
    $conversation = Conversation::withoutTenantScope()->findOrFail($inbound->conversation_id);

    run_flow_engine($tenant, $inbound, $conversation);

    expect(Message::withoutTenantScope()
        ->where('conversation_id', $conversation->id)
        ->where('direction', MessageDirection::Outbound->value)
        ->count())->toBe(0);
})->with([
    'ausente' => [[]],
    'null' => [['handoff_message' => null]],
    'vacío' => [['handoff_message' => '']],
    'espacios' => [['handoff_message' => '   ']],
]);

test('HANDOFF-RUNTIME-03: Human no reabre resolved ni archived y falla con código controlado', function (string $status): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    handoff_runtime_flow($tenant, ['handoff_message' => 'Espera por favor.']);

    $inbound = make_inbound_message($tenant, 'Necesito ayuda');
    $conversation = Conversation::withoutTenantScope()->findOrFail($inbound->conversation_id);
    $conversation->forceFill(['status' => $status])->save();

    run_flow_engine($tenant, $inbound, $conversation->fresh());

    $conversation->refresh();
    $execution = FlowExecution::withoutTenantScope()
        ->where('conversation_id', $conversation->id)
        ->firstOrFail();
    $failure = FlowExecutionLog::withoutTenantScope()
        ->where('execution_id', $execution->id)
        ->where('event', 'execution.failed')
        ->firstOrFail();

    expect($conversation->status->value)->toBe($status)
        ->and($conversation->bot_paused)->toBeFalse()
        ->and($conversation->handoff_requested_at)->toBeNull()
        ->and($execution->status)->toBe(FlowExecutionStatus::Failed)
        ->and($failure->payload['code'])->toBe('CONVERSATION_INVALID_STATE')
        ->and(Message::withoutTenantScope()
            ->where('conversation_id', $conversation->id)
            ->where('direction', MessageDirection::Outbound->value)
            ->count())->toBe(0)
        ->and(AuditLog::query()
            ->where('tenant_id', $tenant->id)
            ->where('action', 'flow.handoff')
            ->count())->toBe(0);
})->with(['resolved', 'archived']);

test('HANDOFF-RUNTIME-04: repetir el mismo handoff no duplica mensaje auditoría ni timestamp', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    handoff_runtime_flow($tenant, ['handoff_message' => 'Espera por favor.']);

    $inbound = make_inbound_message($tenant, 'Necesito ayuda');
    $conversation = Conversation::withoutTenantScope()->findOrFail($inbound->conversation_id);
    run_flow_engine($tenant, $inbound, $conversation);

    $execution = FlowExecution::withoutTenantScope()
        ->where('conversation_id', $conversation->id)
        ->firstOrFail();
    $requestedAt = $conversation->fresh()->handoff_requested_at?->toISOString();

    TenantContext::withId($tenant->id, fn () => app(HumanHandoffService::class)->handoff(
        $tenant,
        $conversation->fresh(),
        $execution,
        'Espera por favor.',
    ));

    expect($conversation->fresh()->handoff_requested_at?->toISOString())->toBe($requestedAt)
        ->and(Message::withoutTenantScope()
            ->where('conversation_id', $conversation->id)
            ->where('direction', MessageDirection::Outbound->value)
            ->count())->toBe(1)
        ->and(AuditLog::query()
            ->where('tenant_id', $tenant->id)
            ->where('action', 'flow.handoff')
            ->count())->toBe(1);
});

test('HANDOFF-RUNTIME-05: un fallo tardío revierte conversación y mensaje del handoff', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $flow = handoff_runtime_flow($tenant, ['handoff_message' => 'Espera por favor.']);
    $inbound = make_inbound_message($tenant, 'Necesito ayuda');
    $conversation = Conversation::withoutTenantScope()->findOrFail($inbound->conversation_id);

    $execution = TenantContext::withId($tenant->id, fn (): FlowExecution => app(FlowExecutionService::class)
        ->start($flow, $conversation, $inbound));

    AuditLog::creating(function (AuditLog $audit): void {
        if ($audit->action === 'flow.handoff') {
            throw new RuntimeException('audit unavailable');
        }
    });
    $service = app(HumanHandoffService::class);

    expect(fn () => TenantContext::withId($tenant->id, fn () => $service->handoff(
        $tenant,
        $conversation,
        $execution,
        'Espera por favor.',
    )))->toThrow(RuntimeException::class, 'audit unavailable');

    expect($conversation->fresh()->bot_paused)->toBeFalse()
        ->and($conversation->fresh()->handoff_requested_at)->toBeNull()
        ->and(Message::withoutTenantScope()
            ->where('conversation_id', $conversation->id)
            ->where('direction', MessageDirection::Outbound->value)
            ->count())->toBe(0)
        ->and(AuditLog::query()
            ->where('tenant_id', $tenant->id)
            ->where('action', 'flow.handoff')
            ->count())->toBe(0);
});

test('HANDOFF-RUNTIME-06: resume conserva handoff y assignment, no revive ni reprocesa y permite un inbound futuro', function (): void {
    Queue::fake();
    Event::fake([ConversationUpdated::class]);

    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    $agent = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');
    make_tenant_member($agent, $tenant, 'agent');

    $flow = make_flow($tenant, make_chatbot($tenant));
    make_flow_graph($flow, [
        ['id' => 'message', 'type' => 'message', 'name' => 'Saludo', 'config' => ['text' => 'Nuevo flujo'], 'is_start' => true],
        ['id' => 'end', 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => 'message', 'to' => 'end'],
    ]);
    $flow->forceFill(['status' => FlowStatus::Published])->save();
    make_trigger($flow, ['type' => FlowTriggerType::NewMessage->value]);

    $oldInbound = make_inbound_message($tenant, 'Mensaje durante handoff');
    $conversation = Conversation::withoutTenantScope()->findOrFail($oldInbound->conversation_id);
    TenantContext::withId($tenant->id, fn () => app(ConversationService::class)
        ->assign($owner, $tenant, $conversation->id, $agent->id));

    $oldExecution = TenantContext::withId($tenant->id, fn (): FlowExecution => FlowExecution::query()->create([
        'flow_id' => $flow->id,
        'conversation_id' => $conversation->id,
        'current_node_id' => 'message',
        'status' => FlowExecutionStatus::HandedOff,
        'variables' => ['custom' => []],
        'attempts' => 0,
        'last_inbound_message_id' => $oldInbound->id,
    ]));
    $requestedAt = now()->subMinute()->startOfSecond();
    $conversation->forceFill([
        'bot_paused' => true,
        'handoff_requested_at' => $requestedAt,
        'flow_execution_id' => null,
    ])->save();

    run_flow_engine($tenant, $oldInbound, $conversation->fresh());

    $this->actingAs($owner)
        ->postJson('/api/v1/tenants/'.$tenant->id.'/conversations/'.$conversation->id.'/resume-bot')
        ->assertOk()
        ->assertJsonPath('conversation.bot_paused', false)
        ->assertJsonPath('conversation.agent.id', $agent->id);

    $conversation->refresh();

    expect($conversation->handoff_requested_at?->toISOString())->toBe($requestedAt->toISOString())
        ->and($conversation->agent_id)->toBe($agent->id)
        ->and($conversation->flow_execution_id)->toBeNull()
        ->and($oldExecution->fresh()->status)->toBe(FlowExecutionStatus::HandedOff)
        ->and(FlowExecution::withoutTenantScope()->where('conversation_id', $conversation->id)->count())->toBe(1)
        ->and(ConversationAssignment::withoutTenantScope()
            ->where('conversation_id', $conversation->id)
            ->whereNull('unassigned_at')
            ->where('agent_id', $agent->id)
            ->count())->toBe(1)
        ->and(ConversationParticipant::withoutTenantScope()
            ->where('conversation_id', $conversation->id)
            ->whereNull('left_at')
            ->where('user_id', $agent->id)
            ->count())->toBe(1);

    $futureInbound = make_inbound_message($tenant, 'Nuevo mensaje');
    run_flow_engine($tenant, $futureInbound, $conversation->fresh());

    expect(FlowExecution::withoutTenantScope()->where('conversation_id', $conversation->id)->count())->toBe(2)
        ->and($oldExecution->fresh()->status)->toBe(FlowExecutionStatus::HandedOff)
        ->and(FlowExecution::withoutTenantScope()
            ->where('conversation_id', $conversation->id)
            ->whereKeyNot($oldExecution->id)
            ->firstOrFail()
            ->status)->toBe(FlowExecutionStatus::Completed);

    Event::assertDispatched(ConversationUpdated::class, fn (ConversationUpdated $event): bool => $event->conversation->id === $conversation->id
        && $event->conversation->bot_paused === false);
});

test('HANDOFF-RUNTIME-07: una pausa manual posterior no reutiliza un marcador handoff histórico', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');
    $conversation = make_conversation($tenant, make_contact($tenant));
    $conversation->forceFill([
        'bot_paused' => false,
        'handoff_requested_at' => now()->subHour(),
    ])->save();

    $this->actingAs($owner)
        ->postJson('/api/v1/tenants/'.$tenant->id.'/conversations/'.$conversation->id.'/pause-bot')
        ->assertOk()
        ->assertJsonPath('conversation.bot_paused', true)
        ->assertJsonPath('conversation.handoff_requested_at', null);

    expect($conversation->fresh()->handoff_requested_at)->toBeNull();
});

test('HANDOFF-RUNTIME-08: un retry repara el crash entre commit de handoff y finish', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $flow = handoff_runtime_flow($tenant);
    $inbound = make_inbound_message($tenant, 'Necesito ayuda');
    $conversation = Conversation::withoutTenantScope()->findOrFail($inbound->conversation_id);
    $execution = TenantContext::withId($tenant->id, fn () => app(FlowExecutionService::class)
        ->start($flow, $conversation, $inbound));

    TenantContext::withId($tenant->id, fn () => app(HumanHandoffService::class)->handoff(
        $tenant,
        $conversation,
        $execution,
        null,
    ));

    expect($execution->fresh()->status)->toBe(FlowExecutionStatus::Running)
        ->and($conversation->fresh()->bot_paused)->toBeTrue()
        ->and($conversation->fresh()->flow_execution_id)->toBe($execution->id);

    run_flow_engine($tenant, $inbound, $conversation->fresh());

    expect($execution->fresh()->status)->toBe(FlowExecutionStatus::HandedOff)
        ->and($conversation->fresh()->flow_execution_id)->toBeNull()
        ->and(FlowExecutionLog::withoutTenantScope()
            ->where('execution_id', $execution->id)
            ->where('event', 'execution.handed_off')
            ->count())->toBe(1)
        ->and(AuditLog::query()
            ->where('tenant_id', $tenant->id)
            ->where('action', 'flow.execution_handed_off')
            ->where('subject_id', $execution->id)
            ->count())->toBe(1);
});

test('HANDOFF-RUNTIME-09: automation previa queda cancelada aunque resume ocurra antes del worker', function (): void {
    Queue::fake();
    Http::fake();

    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');
    handoff_runtime_flow($tenant);
    $inbound = make_inbound_message($tenant, 'Necesito ayuda');
    $conversation = Conversation::withoutTenantScope()->findOrFail($inbound->conversation_id);
    $automation = app(MessageService::class)->createOutbound(
        $tenant,
        $conversation,
        'Automation previa',
        MessageOrigin::Automation,
    );

    run_flow_engine($tenant, $inbound, $conversation);

    expect($automation->fresh()->status)->toBe(MessageStatus::Failed)
        ->and($automation->fresh()->metadata['error_code'])->toBe('BOT_PAUSED_HANDOFF');

    $this->actingAs($owner)
        ->postJson('/api/v1/tenants/'.$tenant->id.'/conversations/'.$conversation->id.'/resume-bot')
        ->assertOk();

    (new SendWhatsAppMessage($tenant->id, $conversation->id, $automation->id))->handle();

    expect($automation->fresh()->status)->toBe(MessageStatus::Failed)
        ->and(MessageSendAttempt::withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->count())->toBe(0);
    Http::assertNothingSent();
});

test('HANDOFF-RUNTIME-10: resume repara un handoff comprometido antes de habilitar el bot', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');
    $flow = handoff_runtime_flow($tenant);
    $inbound = make_inbound_message($tenant, 'Necesito ayuda');
    $conversation = Conversation::withoutTenantScope()->findOrFail($inbound->conversation_id);
    $execution = TenantContext::withId($tenant->id, fn () => app(FlowExecutionService::class)
        ->start($flow, $conversation, $inbound));
    TenantContext::withId($tenant->id, fn () => app(HumanHandoffService::class)->handoff(
        $tenant,
        $conversation,
        $execution,
        null,
    ));

    $this->actingAs($owner)
        ->postJson('/api/v1/tenants/'.$tenant->id.'/conversations/'.$conversation->id.'/resume-bot')
        ->assertOk()
        ->assertJsonPath('conversation.bot_paused', false)
        ->assertJsonPath('conversation.flow_execution_id', null);

    expect($execution->fresh()->status)->toBe(FlowExecutionStatus::HandedOff)
        ->and($conversation->fresh()->handoff_requested_at)->not->toBeNull();
});

test('HANDOFF-RUNTIME-11: execution y conversación del handoff deben pertenecer al mismo tenant y recurso', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $flow = handoff_runtime_flow($tenant);
    $inbound = make_inbound_message($tenant, 'Necesito ayuda');
    $source = Conversation::withoutTenantScope()->findOrFail($inbound->conversation_id);
    $other = make_conversation($tenant, make_contact($tenant));
    $execution = TenantContext::withId($tenant->id, fn () => app(FlowExecutionService::class)
        ->start($flow, $source, $inbound));

    expect(fn () => TenantContext::withId($tenant->id, fn () => app(HumanHandoffService::class)->handoff(
        $tenant,
        $other,
        $execution,
        null,
    )))->toThrow(InvalidArgumentException::class);

    expect($other->fresh()->bot_paused)->toBeFalse()
        ->and($other->fresh()->handoff_requested_at)->toBeNull();
});

test('HANDOFF-RUNTIME-12: recovery reencola el aviso persistido antes de terminalizar', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $flow = handoff_runtime_flow($tenant, ['handoff_message' => 'Espera por favor.']);
    $inbound = make_inbound_message($tenant, 'Necesito ayuda');
    $conversation = Conversation::withoutTenantScope()->findOrFail($inbound->conversation_id);
    $execution = TenantContext::withId($tenant->id, fn () => app(FlowExecutionService::class)
        ->start($flow, $conversation, $inbound));
    TenantContext::withId($tenant->id, fn () => app(HumanHandoffService::class)->handoff(
        $tenant,
        $conversation,
        $execution,
        'Espera por favor.',
    ));
    $notice = Message::withoutTenantScope()
        ->where('conversation_id', $conversation->id)
        ->where('metadata->origin', MessageOrigin::Handoff->value)
        ->firstOrFail();

    Queue::fake();
    run_flow_engine($tenant, $inbound, $conversation->fresh());

    Queue::assertPushed(RecoverPendingWhatsAppMessage::class, fn (RecoverPendingWhatsAppMessage $job): bool => $job->messageId === $notice->id);
    expect($execution->fresh()->status)->toBe(FlowExecutionStatus::HandedOff)
        ->and((new RecoverPendingWhatsAppMessage($tenant->id, $conversation->id, $notice->id))->tries())
        ->toBeGreaterThan((int) config('whatsapp.max_attempts'));
});

test('HANDOFF-RUNTIME-13: resume de execution no revive un handoff comprometido', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');
    $flow = handoff_runtime_flow($tenant);
    $inbound = make_inbound_message($tenant, 'Necesito ayuda');
    $conversation = Conversation::withoutTenantScope()->findOrFail($inbound->conversation_id);
    $execution = TenantContext::withId($tenant->id, fn () => app(FlowExecutionService::class)
        ->start($flow, $conversation, $inbound));
    TenantContext::withId($tenant->id, fn () => app(HumanHandoffService::class)->handoff(
        $tenant,
        $conversation,
        $execution,
        null,
    ));

    $this->actingAs($owner)
        ->postJson('/api/v1/tenants/'.$tenant->id.'/flow-executions/'.$execution->id.'/resume')
        ->assertStatus(409);

    expect($execution->fresh()->status)->toBe(FlowExecutionStatus::HandedOff)
        ->and($conversation->fresh()->bot_paused)->toBeTrue()
        ->and($conversation->fresh()->flow_execution_id)->toBeNull();
});

test('HANDOFF-RUNTIME-14: retries schedule y webhook delegan recovery al motor bajo lock', function (string $source): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $flow = handoff_runtime_flow($tenant);
    $inbound = make_inbound_message($tenant, 'Necesito ayuda');
    $conversation = Conversation::withoutTenantScope()->findOrFail($inbound->conversation_id);
    $execution = TenantContext::withId($tenant->id, fn () => app(FlowExecutionService::class)
        ->start($flow, $conversation, $inbound));
    TenantContext::withId($tenant->id, fn () => app(HumanHandoffService::class)->handoff(
        $tenant,
        $conversation,
        $execution,
        null,
    ));

    $trigger = make_trigger($flow, $source === 'schedule'
        ? [
            'type' => FlowTriggerType::Schedule->value,
            'config' => ['cron' => '* * * * *', 'conversation_id' => $conversation->id],
        ]
        : [
            'type' => FlowTriggerType::Webhook->value,
            'config' => ['conversation_by' => 'conversation_id', 'token_hash' => hash('sha256', 'token')],
        ]);

    if ($source === 'schedule') {
        (new StartFlowFromSchedule($trigger->id))->forTenant($tenant->id)->handle();
    } else {
        (new StartFlowFromWebhook($trigger->id, $conversation->id, 'retry-key'))
            ->forTenant($tenant->id)
            ->handle();
    }

    expect($execution->fresh()->status)->toBe(FlowExecutionStatus::HandedOff)
        ->and($conversation->fresh()->flow_execution_id)->toBeNull();
})->with(['schedule', 'webhook']);
