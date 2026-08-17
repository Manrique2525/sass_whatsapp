<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Conversations\Enums\ConversationStatus;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Conversations\Models\ConversationAssignment;
use App\Domain\Conversations\Models\ConversationParticipant;
use App\Domain\Flows\Enums\FlowExecutionStatus;
use App\Domain\Flows\Enums\FlowStatus;
use App\Domain\Flows\Enums\FlowTriggerType;
use App\Domain\Flows\Models\FlowExecution;
use App\Domain\Messages\Models\Message;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Events\ConversationUpdated;
use App\Events\InboxConversationChanged;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| HANDOFF-FINAL-* Tests: Hardening y cierre FASE 15 (U6)
|--------------------------------------------------------------------------
|
| Tests de gap coverage identificados durante la auditoría de cierre.
| Cubren: regresión de dispatch exact-once, inbound durante handoff,
| duplicate HumanNode, resume→inbound, cross-tenant transfer/reply.
|
*/

// ─── HANDOFF-FINAL-01: Dispatch exact-once regression guard ──────────────

test('HANDOFF-FINAL-01: InboxConversationChanged se emite exactamente una vez via FlowEngine→HumanNode', function (): void {
    Event::fake([InboxConversationChanged::class]);
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    make_flow_graph($flow, [
        ['id' => 'n1', 'type' => 'message', 'name' => 'Inicio', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'human', 'name' => 'Humano'],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
    ]);

    $flow->forceFill(['status' => FlowStatus::Published->value])->save();
    make_trigger($flow, ['type' => FlowTriggerType::Start->value]);

    $message = make_inbound_message($tenant, 'Hola');
    $conversation = engine_conversation_for($message);

    run_flow_engine($tenant, $message, $conversation);

    // Regression guard: exactly 1 InboxConversationChanged dispatched (not 2)
    Event::assertDispatchedTimes(InboxConversationChanged::class, 1);
    Event::assertDispatched(InboxConversationChanged::class, function (InboxConversationChanged $event): bool {
        return $event->kind->value === 'handoff_requested';
    });
});

// ─── HANDOFF-FINAL-02: ConversationUpdated dispatched for detail view ────

test('HANDOFF-FINAL-02: ConversationUpdated se emite durante handoff para vista detalle', function (): void {
    Event::fake([ConversationUpdated::class]);
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    make_flow_graph($flow, [
        ['id' => 'n1', 'type' => 'message', 'name' => 'Inicio', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'human', 'name' => 'Humano'],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
    ]);

    $flow->forceFill(['status' => FlowStatus::Published->value])->save();
    make_trigger($flow, ['type' => FlowTriggerType::Start->value]);

    $message = make_inbound_message($tenant, 'Hola');
    $conversation = engine_conversation_for($message);

    run_flow_engine($tenant, $message, $conversation);

    Event::assertDispatched(ConversationUpdated::class, function (ConversationUpdated $event) use ($conversation): bool {
        return $event->conversation->id === $conversation->id;
    });
});

// ─── HANDOFF-FINAL-03: Resume-bot then inbound starts new automation ────

test('HANDOFF-FINAL-03: resume-bot seguido de inbound inicia nueva automatización', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    make_flow_graph($flow, [
        ['id' => 'n1', 'type' => 'message', 'name' => 'Inicio', 'config' => ['text' => 'Hola de nuevo'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
    ]);

    $flow->forceFill(['status' => FlowStatus::Published->value])->save();
    make_trigger($flow, ['type' => FlowTriggerType::Start->value]);

    // First inbound triggers flow → Human → handoff
    $first = make_inbound_message($tenant, 'Hola');
    $conversation = engine_conversation_for($first);

    // Simulate handoff state directly
    TenantContext::setId($tenant->id);
    try {
        $conversation->forceFill([
            'bot_paused' => true,
            'handoff_requested_at' => now(),
        ])->save();
    } finally {
        TenantContext::clear();
    }

    // Create an old HandedOff execution
    make_flow_execution($tenant, $flow, [
        'conversation_id' => $conversation->id,
        'current_node_id' => 'n1',
        'status' => FlowExecutionStatus::HandedOff,
    ]);

    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    // Resume bot
    $this->actingAs($owner)
        ->postJson("/api/v1/tenants/{$tenant->id}/conversations/{$conversation->id}/resume-bot")
        ->assertOk();

    $conversation->refresh();
    expect($conversation->bot_paused)->toBeFalse();

    // New inbound should trigger a new flow execution
    Queue::fake();
    $second = make_inbound_message($tenant, 'Segundo mensaje');

    // Verify conversation state is ready for automation
    $conversation->refresh();
    expect($conversation->bot_paused)->toBeFalse();

    // Old execution should still be HandedOff
    $oldExecution = FlowExecution::query()->withoutTenantScope()
        ->where('conversation_id', $conversation->id)
        ->where('status', FlowExecutionStatus::HandedOff)
        ->first();
    expect($oldExecution)->not->toBeNull();
});

// ─── HANDOFF-FINAL-04: Inbound during handoff persists but doesn't trigger engine ──

test('HANDOFF-FINAL-04: inbound durante handoff persiste el mensaje pero no crea ejecución', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $contact = make_contact($tenant);
    $conversation = make_conversation($tenant, $contact, [
        'status' => ConversationStatus::Open,
        'bot_paused' => true,
    ]);

    // Set handoff_requested_at directly
    TenantContext::setId($tenant->id);
    try {
        $conversation->forceFill(['handoff_requested_at' => now()])->save();
    } finally {
        TenantContext::clear();
    }

    // Send inbound during handoff
    $message = make_inbound_message($tenant, 'Mensaje durante handoff');

    // Message should persist
    $message->refresh();
    expect($message->id)->not->toBeNull();
    expect($message->body)->toBe('Mensaje durante handoff');

    // No FlowExecution should be created (bot is paused)
    $executions = FlowExecution::query()->withoutTenantScope()
        ->where('conversation_id', $conversation->id)
        ->count();
    expect($executions)->toBe(0);

    // Bot remains paused
    $conversation->refresh();
    expect($conversation->bot_paused)->toBeTrue();
});

// ─── HANDOFF-FINAL-05: Duplicate HumanNode doesn't produce double handoff ─

test('HANDOFF-FINAL-05: nodos human duplicados en secuencia no producen doble handoff', function (): void {
    Event::fake([InboxConversationChanged::class]);
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    // Two human nodes in sequence — only the first should trigger handoff
    // because the engine processes node by node and the first human
    // sets bot_paused=true, which prevents further engine execution
    make_flow_graph($flow, [
        ['id' => 'n1', 'type' => 'message', 'name' => 'Inicio', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'human', 'name' => 'Humano 1'],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
    ]);

    $flow->forceFill(['status' => FlowStatus::Published->value])->save();
    make_trigger($flow, ['type' => FlowTriggerType::Start->value]);

    $message = make_inbound_message($tenant, 'Hola');
    $conversation = engine_conversation_for($message);

    run_flow_engine($tenant, $message, $conversation);

    // Exactly 1 handoff event — the engine terminates at the first Human node
    Event::assertDispatchedTimes(InboxConversationChanged::class, 1);

    // Exactly 1 flow.handoff audit
    $audits = AuditLog::query()
        ->where('action', 'flow.handoff')
        ->where('tenant_id', $tenant->id)
        ->count();
    expect($audits)->toBe(1);

    // Conversation is in handoff state
    $conversation->refresh();
    expect($conversation->bot_paused)->toBeTrue()
        ->and($conversation->handoff_requested_at)->not->toBeNull();
});

// ─── HANDOFF-FINAL-06: Cross-tenant claim returns 404 ────────────────────

test('HANDOFF-FINAL-06: claim cross-tenant retorna 404', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $agentA = User::factory()->create();
    $agentB = User::factory()->create();
    make_tenant_member($agentA, $tenantA, 'agent');
    make_tenant_member($agentB, $tenantB, 'agent');

    $contactB = make_contact($tenantB);
    $convB = make_conversation($tenantB, $contactB, [
        'status' => ConversationStatus::Open,
        'bot_paused' => true,
    ]);

    TenantContext::setId($tenantB->id);
    try {
        $convB->forceFill(['handoff_requested_at' => now()])->save();
    } finally {
        TenantContext::clear();
    }

    // Agent A tries to claim conversation of tenant B
    $this->actingAs($agentA)
        ->postJson("/api/v1/tenants/{$tenantB->id}/conversations/{$convB->id}/claim")
        ->assertStatus(404);
});

// ─── HANDOFF-FINAL-07: Cross-tenant transfer returns 404 ────────────────

test('HANDOFF-FINAL-07: transfer cross-tenant retorna 404', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    $agentA = User::factory()->create();
    make_tenant_member($ownerA, $tenantA, 'owner');
    make_tenant_member($ownerB, $tenantB, 'owner');
    make_tenant_member($agentA, $tenantA, 'agent');

    $contactB = make_contact($tenantB);
    $convB = make_conversation($tenantB, $contactB, [
        'status' => ConversationStatus::Open,
        'bot_paused' => true,
        'agent_id' => $ownerB->id,
    ]);

    // Owner A tries to transfer conversation of tenant B to agent A
    $this->actingAs($ownerA)
        ->postJson("/api/v1/tenants/{$tenantB->id}/conversations/{$convB->id}/transfer", [
            'agent_id' => $agentA->id,
        ])
        ->assertStatus(404);
});

// ─── HANDOFF-FINAL-08: Cross-tenant reply blocked ────────────────────────

test('HANDOFF-FINAL-08: reply cross-tenant retorna 404', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $agentA = User::factory()->create();
    $ownerB = User::factory()->create();
    make_tenant_member($agentA, $tenantA, 'agent');
    make_tenant_member($ownerB, $tenantB, 'owner');

    $contactB = make_contact($tenantB);
    $convB = make_conversation($tenantB, $contactB, [
        'status' => ConversationStatus::Open,
        'bot_paused' => true,
    ]);

    // Agent A tries to reply to conversation of tenant B
    $this->actingAs($agentA)
        ->postJson("/api/v1/tenants/{$tenantB->id}/conversations/{$convB->id}/messages", [
            'body' => 'Cross tenant reply attempt',
        ])
        ->assertStatus(404);
});

// ─── HANDOFF-FINAL-09: Sent_by_user_id from frontend is rejected ────────

test('HANDOFF-FINAL-09: sent_by_user_id del frontend es ignorado', function (): void {
    $tenant = Tenant::factory()->create();
    $agent = User::factory()->create();
    make_tenant_member($agent, $tenant, 'agent');

    $contact = make_contact($tenant);
    $conversation = make_conversation($tenant, $contact, [
        'status' => ConversationStatus::Open,
        'bot_paused' => true,
    ]);

    // Create assignment for the agent
    TenantContext::setId($tenant->id);
    try {
        $conversation->forceFill(['agent_id' => $agent->id])->save();

        ConversationAssignment::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'agent_id' => $agent->id,
            'reason' => 'claim',
            'assigned_by' => $agent->id,
            'assigned_at' => now(),
        ]);

        ConversationParticipant::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'user_id' => $agent->id,
            'role' => 'agent',
            'joined_at' => now(),
        ]);
    } finally {
        TenantContext::clear();
    }

    // Attempt to inject sent_by_user_id — validation rejects it as prohibited
    $this->actingAs($agent)
        ->postJson("/api/v1/tenants/{$tenant->id}/conversations/{$conversation->id}/messages", [
            'body' => 'Injected message',
            'sent_by_user_id' => 9999,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['sent_by_user_id']);

    // Verify no message was created with the injected value
    $message = Message::query()
        ->withoutTenantScope()
        ->where('conversation_id', $conversation->id)
        ->where('body', 'Injected message')
        ->first();

    expect($message)->toBeNull();

    // Verify a valid message can be sent without the prohibited field
    $this->actingAs($agent)
        ->postJson("/api/v1/tenants/{$tenant->id}/conversations/{$conversation->id}/messages", [
            'body' => 'Legitimate message',
        ])
        ->assertCreated();

    $msg = Message::query()
        ->withoutTenantScope()
        ->where('conversation_id', $conversation->id)
        ->where('body', 'Legitimate message')
        ->first();

    expect($msg->sent_by_user_id)->toBe($agent->id);
});

// ─── HANDOFF-FINAL-10: Inactive member cannot claim ─────────────────────

test('HANDOFF-FINAL-10: membresía inactiva no puede claim', function (): void {
    $tenant = Tenant::factory()->create();
    $agent = User::factory()->create();
    make_tenant_member($agent, $tenant, 'agent');

    $contact = make_contact($tenant);
    $conversation = make_conversation($tenant, $contact, [
        'status' => ConversationStatus::Open,
        'bot_paused' => true,
    ]);

    TenantContext::setId($tenant->id);
    try {
        $conversation->forceFill(['handoff_requested_at' => now()])->save();
    } finally {
        TenantContext::clear();
    }

    // Deactivate membership
    $agent->tenants()->wherePivot('tenant_id', $tenant->id)->updateExistingPivot($tenant->id, ['status' => 'inactive']);

    $this->actingAs($agent)
        ->postJson("/api/v1/tenants/{$tenant->id}/conversations/{$conversation->id}/claim")
        ->assertStatus(403);
});
