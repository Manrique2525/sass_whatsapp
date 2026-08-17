<?php

declare(strict_types=1);

use App\Application\Conversations\Services\HumanHandoffService;
use App\Domain\Conversations\Enums\ConversationStatus;
use App\Domain\Conversations\Enums\InboxConversationChangeKind;
use App\Domain\Conversations\Exceptions\ConversationInvalidStateException;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Flows\Enums\FlowExecutionStatus;
use App\Domain\Flows\Enums\FlowStatus;
use App\Domain\Flows\Enums\FlowTriggerType;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Events\InboxConversationChanged;
use App\Http\Resources\ConversationResource;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/**
 * Suite HANDOFF-REALTIME-*: tests de realtime tenant-wide para el Inbox (U4).
 *
 * Verifica emisión de `InboxConversationChanged` afterCommit, canal privado
 * tenant-wide, autorización cross-tenant, kind válido, payload seguro y
 * event_id único.
 */

// ─── Helpers ────────────────────────────────────────────────────────────────

/**
 * Crea un tenant, owner, contact y conversación para tests de realtime.
 */
function realtime_setup(): array
{
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');
    $contact = make_contact($tenant);
    $conversation = make_conversation($tenant, $contact, [
        'status' => ConversationStatus::Open,
    ]);

    return compact('tenant', 'owner', 'contact', 'conversation');
}

// ─── RT-05: Handoff emite afterCommit ───────────────────────────────────────

test('HANDOFF-REALTIME-05: human handoff emite InboxConversationChanged after commit', function (): void {
    Event::fake([InboxConversationChanged::class]);

    $tenant = Tenant::factory()->create();
    $contact = make_contact($tenant);
    $conversation = make_conversation($tenant, $contact, [
        'status' => ConversationStatus::Open,
    ]);

    $flow = make_flow($tenant, make_chatbot($tenant));
    make_flow_graph($flow, [
        [
            'id' => 'human',
            'type' => 'human',
            'name' => 'Atención humana',
            'config' => [],
            'is_start' => true,
        ],
    ], []);

    $flow->forceFill(['status' => FlowStatus::Published])->save();
    make_trigger($flow, ['type' => FlowTriggerType::Start->value]);

    $execution = make_flow_execution($tenant, $flow, [
        'conversation_id' => $conversation->id,
        'current_node_id' => 'human',
        'status' => FlowExecutionStatus::Running,
    ]);

    TenantContext::withId($tenant->id, function () use ($tenant, $conversation, $execution): void {
        app(HumanHandoffService::class)->handoff(
            $tenant,
            $conversation,
            $execution,
            null,
        );
    });

    Event::assertDispatched(InboxConversationChanged::class, function (InboxConversationChanged $event) use ($tenant, $conversation): bool {
        return $event->afterCommit === true
            && $event->kind->value === 'handoff_requested'
            && $event->conversation->id === $conversation->id
            && $event->conversation->tenant_id === $tenant->id
            && $event->broadcastAs() === 'InboxConversationChanged';
    });
});

// ─── RT-06: Rollback no emite ──────────────────────────────────────────────

test('HANDOFF-REALTIME-06: rollback en transacción no emite InboxConversationChanged', function (): void {
    Event::fake([InboxConversationChanged::class]);

    $tenant = Tenant::factory()->create();
    $contact = make_contact($tenant);
    $conversation = make_conversation($tenant, $contact, [
        'status' => ConversationStatus::Resolved,
    ]);

    $flow = make_flow($tenant, make_chatbot($tenant));
    make_flow_graph($flow, [
        [
            'id' => 'human',
            'type' => 'human',
            'name' => 'Atención humana',
            'config' => [],
            'is_start' => true,
        ],
    ], []);

    $flow->forceFill(['status' => FlowStatus::Published])->save();
    make_trigger($flow, ['type' => FlowTriggerType::Start->value]);

    $execution = make_flow_execution($tenant, $flow, [
        'conversation_id' => $conversation->id,
        'current_node_id' => 'human',
        'status' => FlowExecutionStatus::Running,
    ]);

    try {
        TenantContext::withId($tenant->id, function () use ($tenant, $conversation, $execution): void {
            app(HumanHandoffService::class)->handoff(
                $tenant,
                $conversation,
                $execution,
                null,
            );
        });
    } catch (ConversationInvalidStateException) {
        // Expected: resolved conversation → handoff falls back → transaction rolled back
    }

    Event::assertNotDispatched(InboxConversationChanged::class);
});

// ─── RT-07: Claim emite ────────────────────────────────────────────────────

test('HANDOFF-REALTIME-07: claim emite InboxConversationChanged con kind claimed', function (): void {
    Event::fake([InboxConversationChanged::class]);

    $setup = realtime_setup();
    $agent = User::factory()->create();
    make_tenant_member($agent, $setup['tenant'], 'agent');

    $conversation = $setup['conversation'];
    $conversation->forceFill([
        'bot_paused' => true,
        'handoff_requested_at' => now(),
    ])->save();

    $this->actingAs($agent)
        ->postJson(
            '/api/v1/tenants/'.$setup['tenant']->id.'/conversations/'.$conversation->id.'/claim',
        )
        ->assertOk();

    Event::assertDispatched(InboxConversationChanged::class, function (InboxConversationChanged $event) use ($setup, $conversation): bool {
        return $event->kind->value === 'claimed'
            && $event->conversation->id === $conversation->id
            && $event->conversation->tenant_id === $setup['tenant']->id;
    });
});

// ─── RT-08: Assign emite ───────────────────────────────────────────────────

test('HANDOFF-REALTIME-08: assign emite InboxConversationChanged con kind assigned', function (): void {
    Event::fake([InboxConversationChanged::class]);

    $setup = realtime_setup();
    $agent = User::factory()->create();
    make_tenant_member($agent, $setup['tenant'], 'agent');

    $this->actingAs($setup['owner'])
        ->postJson(
            '/api/v1/tenants/'.$setup['tenant']->id.'/conversations/'.$setup['conversation']->id.'/assign',
            ['agent_id' => $agent->id],
        )
        ->assertOk();

    Event::assertDispatched(InboxConversationChanged::class, function (InboxConversationChanged $event) use ($setup): bool {
        return $event->kind->value === 'assigned'
            && $event->conversation->id === $setup['conversation']->id
            && $event->conversation->tenant_id === $setup['tenant']->id;
    });
});

// ─── RT-09: Transfer emite ─────────────────────────────────────────────────

test('HANDOFF-REALTIME-09: transfer emite InboxConversationChanged con kind transferred', function (): void {
    Event::fake([InboxConversationChanged::class]);

    $setup = realtime_setup();
    $agentA = User::factory()->create();
    $agentB = User::factory()->create();
    make_tenant_member($agentA, $setup['tenant'], 'agent');
    make_tenant_member($agentB, $setup['tenant'], 'agent');

    // First assign to agentA
    $this->actingAs($setup['owner'])
        ->postJson(
            '/api/v1/tenants/'.$setup['tenant']->id.'/conversations/'.$setup['conversation']->id.'/assign',
            ['agent_id' => $agentA->id],
        )
        ->assertOk();

    Event::fake([InboxConversationChanged::class]);

    // Then transfer to agentB
    $this->actingAs($setup['owner'])
        ->postJson(
            '/api/v1/tenants/'.$setup['tenant']->id.'/conversations/'.$setup['conversation']->id.'/transfer',
            ['agent_id' => $agentB->id],
        )
        ->assertOk();

    Event::assertDispatched(InboxConversationChanged::class, function (InboxConversationChanged $event) use ($setup): bool {
        return $event->kind->value === 'transferred'
            && $event->conversation->id === $setup['conversation']->id
            && $event->conversation->tenant_id === $setup['tenant']->id;
    });
});

// ─── RT-10: Resume emite ───────────────────────────────────────────────────

test('HANDOFF-REALTIME-10: resume bot emite InboxConversationChanged con kind bot_resumed', function (): void {
    Event::fake([InboxConversationChanged::class]);

    $setup = realtime_setup();
    $setup['conversation']->forceFill(['bot_paused' => true])->save();

    $this->actingAs($setup['owner'])
        ->postJson(
            '/api/v1/tenants/'.$setup['tenant']->id.'/conversations/'.$setup['conversation']->id.'/resume-bot',
        )
        ->assertOk();

    Event::assertDispatched(InboxConversationChanged::class, function (InboxConversationChanged $event) use ($setup): bool {
        return $event->kind->value === 'bot_resumed'
            && $event->conversation->id === $setup['conversation']->id
            && $event->conversation->tenant_id === $setup['tenant']->id;
    });
});

// ─── RT-11: Close emite ────────────────────────────────────────────────────

test('HANDOFF-REALTIME-11: close emite InboxConversationChanged con kind conversation_updated', function (): void {
    Event::fake([InboxConversationChanged::class]);

    $setup = realtime_setup();

    $this->actingAs($setup['owner'])
        ->postJson(
            '/api/v1/tenants/'.$setup['tenant']->id.'/conversations/'.$setup['conversation']->id.'/close',
        )
        ->assertOk();

    Event::assertDispatched(InboxConversationChanged::class, function (InboxConversationChanged $event) use ($setup): bool {
        return $event->kind->value === 'conversation_updated'
            && $event->conversation->id === $setup['conversation']->id;
    });
});

// ─── RT-11b: Reopen emite ──────────────────────────────────────────────────

test('HANDOFF-REALTIME-11b: reopen emite InboxConversationChanged con kind conversation_updated', function (): void {
    Event::fake([InboxConversationChanged::class]);

    $setup = realtime_setup();
    $setup['conversation']->forceFill(['status' => ConversationStatus::Resolved])->save();

    $this->actingAs($setup['owner'])
        ->postJson(
            '/api/v1/tenants/'.$setup['tenant']->id.'/conversations/'.$setup['conversation']->id.'/reopen',
        )
        ->assertOk();

    Event::assertDispatched(InboxConversationChanged::class, function (InboxConversationChanged $event) use ($setup): bool {
        return $event->kind->value === 'conversation_updated'
            && $event->conversation->id === $setup['conversation']->id;
    });
});

// ─── RT-12: Payload Resource seguro ─────────────────────────────────────────

test('HANDOFF-REALTIME-12: payload usa ConversationResource y no expone tenant_id directamente', function (): void {
    Event::fake([InboxConversationChanged::class]);

    $setup = realtime_setup();

    TenantContext::withId($setup['tenant']->id, function () use ($setup): void {
        $conversation = $setup['conversation'];
        $conversation->loadMissing(['contact', 'agent']);

        event(new InboxConversationChanged(
            $conversation,
            InboxConversationChangeKind::HandoffRequested,
        ));
    });

    Event::assertDispatched(InboxConversationChanged::class, function (InboxConversationChanged $event) use ($setup): bool {
        $payload = $event->broadcastWith();

        // Must contain event_id, kind, conversation
        if (! isset($payload['event_id'], $payload['kind'], $payload['conversation'])) {
            return false;
        }

        $conv = $payload['conversation'];

        // Must not contain sensitive fields
        if (isset($conv['tenant_id']) || isset($conv['context']) || isset($conv['flow_execution_id'])) {
            return false;
        }

        // Must contain safe fields from ConversationResource
        return isset($conv['id'], $conv['status'], $conv['status_label'], $conv['created_at'])
            && $conv['id'] === $setup['conversation']->id;
    });
});

// ─── RT-13: event_id único ──────────────────────────────────────────────────

test('HANDOFF-REALTIME-13: cada evento tiene event_id único', function (): void {
    $tenant = Tenant::factory()->create();
    $contact = make_contact($tenant);
    $conversation = make_conversation($tenant, $contact);

    $ids = [];
    for ($i = 0; $i < 10; $i++) {
        TenantContext::withId($tenant->id, function () use ($conversation, &$ids): void {
            $event = new InboxConversationChanged(
                $conversation,
                InboxConversationChangeKind::HandoffRequested,
            );
            $ids[] = $event->eventId;
        });
    }

    expect($ids)->toHaveCount(10);
    expect(array_unique($ids))->toHaveCount(10);
});

// ─── RT-14: kind enum válido ────────────────────────────────────────────────

test('HANDOFF-REALTIME-14: todos los kinds del enum son válidos en broadcastWith', function (): void {
    $tenant = Tenant::factory()->create();
    $contact = make_contact($tenant);
    $conversation = make_conversation($tenant, $contact);

    $kinds = [
        InboxConversationChangeKind::HandoffRequested,
        InboxConversationChangeKind::Assigned,
        InboxConversationChangeKind::Claimed,
        InboxConversationChangeKind::Transferred,
        InboxConversationChangeKind::BotResumed,
        InboxConversationChangeKind::ConversationUpdated,
    ];

    foreach ($kinds as $kind) {
        $event = new InboxConversationChanged($conversation, $kind);
        $payload = $event->broadcastWith();

        expect($payload['kind'])->toBe($kind->value);
    }
});

// ─── RT-15: Canal privado tenant-wide channel name ──────────────────────────

test('HANDOFF-REALTIME-15: broadcastOn emite al canal privado tenant.{id}.inbox', function (): void {
    $tenant = Tenant::factory()->create();
    $contact = make_contact($tenant);
    $conversation = make_conversation($tenant, $contact);

    $event = new InboxConversationChanged(
        $conversation,
        InboxConversationChangeKind::HandoffRequested,
    );

    $channels = $event->broadcastOn();

    expect($channels)->toHaveCount(1);
    expect($channels[0]->name)->toBe('private-tenant.'.$tenant->id.'.inbox');
});

// ─── RT-05b: HumanHandoffService loads contact+agent before dispatch ─────

test('HANDOFF-REALTIME-05b: InboxConversationChanged incluye contact y agent cargados', function (): void {
    Event::fake([InboxConversationChanged::class]);

    $tenant = Tenant::factory()->create();
    $contact = make_contact($tenant);
    $conversation = make_conversation($tenant, $contact, [
        'status' => ConversationStatus::Open,
    ]);

    $flow = make_flow($tenant, make_chatbot($tenant));
    make_flow_graph($flow, [
        [
            'id' => 'human',
            'type' => 'human',
            'name' => 'Atención humana',
            'config' => [],
            'is_start' => true,
        ],
    ], []);

    $flow->forceFill(['status' => FlowStatus::Published])->save();
    make_trigger($flow, ['type' => FlowTriggerType::Start->value]);

    $execution = make_flow_execution($tenant, $flow, [
        'conversation_id' => $conversation->id,
        'current_node_id' => 'human',
        'status' => FlowExecutionStatus::Running,
    ]);

    TenantContext::withId($tenant->id, function () use ($tenant, $conversation, $execution): void {
        app(HumanHandoffService::class)->handoff(
            $tenant,
            $conversation,
            $execution,
            null,
        );
    });

    Event::assertDispatched(InboxConversationChanged::class, function (InboxConversationChanged $event) use ($contact): bool {
        $payload = $event->broadcastWith();

        // Verify contact is loaded in the serialized conversation
        return isset($payload['conversation']['contact'])
            && $payload['conversation']['contact']['id'] === $contact->id;
    });
});
