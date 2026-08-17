<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Conversations\Models\ConversationAssignment;
use App\Domain\Conversations\Models\ConversationParticipant;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\TenantPermission;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Models\User;
use App\Events\ConversationUpdated;
use App\Infrastructure\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/**
 * @return array{tenant: Tenant, owner: User, agent_a: User, agent_b: User, conversation: Conversation}
 */
function handoff_assignment_setup(bool $handoff = false): array
{
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    $agentA = User::factory()->create();
    $agentB = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');
    make_tenant_member($agentA, $tenant, 'agent');
    make_tenant_member($agentB, $tenant, 'agent');
    $conversation = make_conversation($tenant, make_contact($tenant));

    if ($handoff) {
        $conversation->forceFill([
            'bot_paused' => true,
            'handoff_requested_at' => now(),
        ])->save();
    }

    return [
        'tenant' => $tenant,
        'owner' => $owner,
        'agent_a' => $agentA,
        'agent_b' => $agentB,
        'conversation' => $conversation,
    ];
}

function handoff_assignment_url(Tenant $tenant, Conversation $conversation, string $action): string
{
    return sprintf(
        '/api/v1/tenants/%s/conversations/%s/%s',
        $tenant->id,
        $conversation->id,
        $action,
    );
}

test('HA-01: assign sin agente asigna al agente A', function (): void {
    ['tenant' => $tenant, 'owner' => $owner, 'agent_a' => $agent, 'conversation' => $conversation] = handoff_assignment_setup();

    $this->actingAs($owner)
        ->postJson(handoff_assignment_url($tenant, $conversation, 'assign'), ['agent_id' => $agent->id])
        ->assertOk()
        ->assertJsonPath('conversation.agent.id', $agent->id);

    expect($conversation->fresh()?->agent_id)->toBe($agent->id);
});

test('HA-02: assign al mismo agente es idempotente si las proyecciones coinciden', function (): void {
    ['tenant' => $tenant, 'owner' => $owner, 'agent_a' => $agent, 'conversation' => $conversation] = handoff_assignment_setup();
    $url = handoff_assignment_url($tenant, $conversation, 'assign');

    $this->actingAs($owner)->postJson($url, ['agent_id' => $agent->id])->assertOk();
    $this->actingAs($owner)->postJson($url, ['agent_id' => $agent->id])->assertOk();

    expect(ConversationAssignment::withoutTenantScope()->where('conversation_id', $conversation->id)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'conversation.assigned')->count())->toBe(1);
});

test('HA-03: assign rechaza target con membership inactive', function (): void {
    ['tenant' => $tenant, 'owner' => $owner, 'agent_a' => $agent, 'conversation' => $conversation] = handoff_assignment_setup();
    DB::table('tenant_users')->where('tenant_id', $tenant->id)->where('user_id', $agent->id)->update(['status' => 'disabled']);

    $this->actingAs($owner)
        ->postJson(handoff_assignment_url($tenant, $conversation, 'assign'), ['agent_id' => $agent->id])
        ->assertStatus(422)
        ->assertJsonPath('code', 'AGENT_NOT_IN_TENANT');
});

test('HA-04: assign rechaza target de otro tenant', function (): void {
    ['tenant' => $tenant, 'owner' => $owner, 'conversation' => $conversation] = handoff_assignment_setup();
    $otherTenant = Tenant::factory()->create();
    $otherAgent = User::factory()->create();
    make_tenant_member($otherAgent, $otherTenant, 'agent');

    $this->actingAs($owner)
        ->postJson(handoff_assignment_url($tenant, $conversation, 'assign'), ['agent_id' => $otherAgent->id])
        ->assertStatus(422)
        ->assertJsonPath('code', 'AGENT_NOT_IN_TENANT');
});

test('HA-05: assign sobre conversación cross-tenant devuelve 404', function (): void {
    ['tenant' => $tenantA, 'owner' => $ownerA, 'agent_a' => $agentA] = handoff_assignment_setup();
    ['conversation' => $conversationB] = handoff_assignment_setup();

    $this->actingAs($ownerA)
        ->postJson(handoff_assignment_url($tenantA, $conversationB, 'assign'), ['agent_id' => $agentA->id])
        ->assertNotFound();
});

test('HA-06: assign crea participant activo con el users.id correcto', function (): void {
    ['tenant' => $tenant, 'owner' => $owner, 'agent_a' => $agent, 'conversation' => $conversation] = handoff_assignment_setup();

    $this->actingAs($owner)
        ->postJson(handoff_assignment_url($tenant, $conversation, 'assign'), ['agent_id' => $agent->id])
        ->assertOk();

    $this->assertDatabaseHas('conversation_participants', [
        'tenant_id' => $tenant->id,
        'conversation_id' => $conversation->id,
        'user_id' => $agent->id,
        'role' => 'agent',
        'left_at' => null,
    ]);
});

test('HA-07: assign mantiene una única assignment abierta', function (): void {
    ['tenant' => $tenant, 'owner' => $owner, 'agent_a' => $agent, 'conversation' => $conversation] = handoff_assignment_setup();

    $this->actingAs($owner)
        ->postJson(handoff_assignment_url($tenant, $conversation, 'assign'), ['agent_id' => $agent->id])
        ->assertOk();

    expect(ConversationAssignment::withoutTenantScope()
        ->where('conversation_id', $conversation->id)
        ->whereNull('unassigned_at')
        ->count())->toBe(1);
});

test('HA-08: assign audita actor y payload mínimo aprobado', function (): void {
    ['tenant' => $tenant, 'owner' => $owner, 'agent_a' => $agent, 'conversation' => $conversation] = handoff_assignment_setup();

    $this->actingAs($owner)
        ->postJson(handoff_assignment_url($tenant, $conversation, 'assign'), ['agent_id' => $agent->id])
        ->assertOk();

    $audit = AuditLog::query()->where('action', 'conversation.assigned')->firstOrFail();
    $data = $audit->data;
    ksort($data);

    expect($audit->tenant_id)->toBe($tenant->id)
        ->and($audit->actor_user_id)->toBe($owner->id)
        ->and($data)->toBe([
            'agent_id' => $agent->id,
            'conversation_id' => $conversation->id,
            'previous_agent_id' => null,
            'reason' => 'manual',
        ]);
});

test('HA-09: assign al mismo agente con proyecciones corruptas falla controlado', function (): void {
    ['tenant' => $tenant, 'owner' => $owner, 'agent_a' => $agent, 'conversation' => $conversation] = handoff_assignment_setup();
    $url = handoff_assignment_url($tenant, $conversation, 'assign');
    $this->actingAs($owner)->postJson($url, ['agent_id' => $agent->id])->assertOk();
    ConversationAssignment::withoutTenantScope()->where('conversation_id', $conversation->id)->delete();

    $this->actingAs($owner)
        ->postJson($url, ['agent_id' => $agent->id])
        ->assertStatus(409)
        ->assertJsonPath('code', 'CONVERSATION_ASSIGNMENT_INCONSISTENT');
});

test('HT-01: transfer A a B actualiza la fuente operativa', function (): void {
    ['tenant' => $tenant, 'owner' => $owner, 'agent_a' => $agentA, 'agent_b' => $agentB, 'conversation' => $conversation] = handoff_assignment_setup();
    $this->actingAs($owner)->postJson(handoff_assignment_url($tenant, $conversation, 'assign'), ['agent_id' => $agentA->id])->assertOk();

    $this->actingAs($owner)
        ->postJson(handoff_assignment_url($tenant, $conversation, 'transfer'), ['agent_id' => $agentB->id])
        ->assertOk()
        ->assertJsonPath('conversation.agent.id', $agentB->id);
});

test('HT-02: transfer sin agente devuelve conflicto', function (): void {
    ['tenant' => $tenant, 'owner' => $owner, 'agent_b' => $agentB, 'conversation' => $conversation] = handoff_assignment_setup();

    $this->actingAs($owner)
        ->postJson(handoff_assignment_url($tenant, $conversation, 'transfer'), ['agent_id' => $agentB->id])
        ->assertStatus(409)
        ->assertJsonPath('code', 'CONVERSATION_NOT_ASSIGNED');
});

test('HT-03: transfer A a A devuelve conflicto explícito', function (): void {
    ['tenant' => $tenant, 'owner' => $owner, 'agent_a' => $agentA, 'conversation' => $conversation] = handoff_assignment_setup();
    $this->actingAs($owner)->postJson(handoff_assignment_url($tenant, $conversation, 'assign'), ['agent_id' => $agentA->id])->assertOk();

    $this->actingAs($owner)
        ->postJson(handoff_assignment_url($tenant, $conversation, 'transfer'), ['agent_id' => $agentA->id])
        ->assertStatus(409)
        ->assertJsonPath('code', 'CONVERSATION_TRANSFER_SAME_AGENT');
});

test('HT-04: transfer rechaza target inactive', function (): void {
    ['tenant' => $tenant, 'owner' => $owner, 'agent_a' => $agentA, 'agent_b' => $agentB, 'conversation' => $conversation] = handoff_assignment_setup();
    $this->actingAs($owner)->postJson(handoff_assignment_url($tenant, $conversation, 'assign'), ['agent_id' => $agentA->id])->assertOk();
    DB::table('tenant_users')->where('tenant_id', $tenant->id)->where('user_id', $agentB->id)->update(['status' => 'disabled']);

    $this->actingAs($owner)
        ->postJson(handoff_assignment_url($tenant, $conversation, 'transfer'), ['agent_id' => $agentB->id])
        ->assertStatus(422)
        ->assertJsonPath('code', 'AGENT_NOT_IN_TENANT');
});

test('HT-05: transfer rechaza target de otro tenant', function (): void {
    ['tenant' => $tenant, 'owner' => $owner, 'agent_a' => $agentA, 'conversation' => $conversation] = handoff_assignment_setup();
    $otherTenant = Tenant::factory()->create();
    $otherAgent = User::factory()->create();
    make_tenant_member($otherAgent, $otherTenant, 'agent');
    $this->actingAs($owner)->postJson(handoff_assignment_url($tenant, $conversation, 'assign'), ['agent_id' => $agentA->id])->assertOk();

    $this->actingAs($owner)
        ->postJson(handoff_assignment_url($tenant, $conversation, 'transfer'), ['agent_id' => $otherAgent->id])
        ->assertStatus(422)
        ->assertJsonPath('code', 'AGENT_NOT_IN_TENANT');
});

test('HT-06: transfer cierra la assignment de A y abre la de B', function (): void {
    ['tenant' => $tenant, 'owner' => $owner, 'agent_a' => $agentA, 'agent_b' => $agentB, 'conversation' => $conversation] = handoff_assignment_setup();
    $this->actingAs($owner)->postJson(handoff_assignment_url($tenant, $conversation, 'assign'), ['agent_id' => $agentA->id])->assertOk();
    $this->actingAs($owner)->postJson(handoff_assignment_url($tenant, $conversation, 'transfer'), ['agent_id' => $agentB->id])->assertOk();

    expect(ConversationAssignment::withoutTenantScope()->where('conversation_id', $conversation->id)->where('agent_id', $agentA->id)->firstOrFail()->unassigned_at)->not->toBeNull()
        ->and(ConversationAssignment::withoutTenantScope()->where('conversation_id', $conversation->id)->where('agent_id', $agentB->id)->firstOrFail()->unassigned_at)->toBeNull();
});

test('HT-07: transfer marca participant A como left', function (): void {
    ['tenant' => $tenant, 'owner' => $owner, 'agent_a' => $agentA, 'agent_b' => $agentB, 'conversation' => $conversation] = handoff_assignment_setup();
    $this->actingAs($owner)->postJson(handoff_assignment_url($tenant, $conversation, 'assign'), ['agent_id' => $agentA->id])->assertOk();
    $this->actingAs($owner)->postJson(handoff_assignment_url($tenant, $conversation, 'transfer'), ['agent_id' => $agentB->id])->assertOk();

    expect(ConversationParticipant::withoutTenantScope()->where('conversation_id', $conversation->id)->where('user_id', $agentA->id)->firstOrFail()->left_at)->not->toBeNull();
});

test('HT-08: transfer reactiva participant B sin sobrescribir joined_at', function (): void {
    ['tenant' => $tenant, 'owner' => $owner, 'agent_a' => $agentA, 'agent_b' => $agentB, 'conversation' => $conversation] = handoff_assignment_setup();
    $originalJoinedAt = now()->subDay()->startOfSecond();
    TenantContext::withId($tenant->id, fn (): ConversationParticipant => ConversationParticipant::query()->create([
        'conversation_id' => $conversation->id,
        'user_id' => $agentB->id,
        'role' => 'agent',
        'joined_at' => $originalJoinedAt,
        'left_at' => now()->subHour(),
    ]));
    $this->actingAs($owner)->postJson(handoff_assignment_url($tenant, $conversation, 'assign'), ['agent_id' => $agentA->id])->assertOk();
    $this->actingAs($owner)->postJson(handoff_assignment_url($tenant, $conversation, 'transfer'), ['agent_id' => $agentB->id])->assertOk();

    $participant = ConversationParticipant::withoutTenantScope()->where('conversation_id', $conversation->id)->where('user_id', $agentB->id)->firstOrFail();
    expect($participant->left_at)->toBeNull()
        ->and($participant->joined_at?->equalTo($originalJoinedAt))->toBeTrue();
});

test('HT-09: transfer audita previous agent, target y reason', function (): void {
    ['tenant' => $tenant, 'owner' => $owner, 'agent_a' => $agentA, 'agent_b' => $agentB, 'conversation' => $conversation] = handoff_assignment_setup();
    $this->actingAs($owner)->postJson(handoff_assignment_url($tenant, $conversation, 'assign'), ['agent_id' => $agentA->id])->assertOk();
    $this->actingAs($owner)->postJson(handoff_assignment_url($tenant, $conversation, 'transfer'), ['agent_id' => $agentB->id])->assertOk();

    $audit = AuditLog::query()->where('action', 'conversation.transferred')->firstOrFail();
    expect($audit->actor_user_id)->toBe($owner->id)
        ->and($audit->data['conversation_id'])->toBe($conversation->id)
        ->and($audit->data['previous_agent_id'])->toBe($agentA->id)
        ->and($audit->data['agent_id'])->toBe($agentB->id)
        ->and($audit->data['reason'])->toBe('transfer');
});

test('HC-01: agent reclama conversación handoff sin asignar para sí mismo', function (): void {
    ['tenant' => $tenant, 'agent_a' => $agent, 'conversation' => $conversation] = handoff_assignment_setup(handoff: true);

    $this->actingAs($agent)
        ->postJson(handoff_assignment_url($tenant, $conversation, 'claim'))
        ->assertOk()
        ->assertJsonPath('conversation.agent.id', $agent->id);
});

test('HC-02: claim rechaza agent_id aportado por el cliente', function (): void {
    ['tenant' => $tenant, 'agent_a' => $agentA, 'agent_b' => $agentB, 'conversation' => $conversation] = handoff_assignment_setup(handoff: true);

    $this->actingAs($agentA)
        ->postJson(handoff_assignment_url($tenant, $conversation, 'claim'), ['agent_id' => $agentB->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('agent_id');

    expect($conversation->fresh()?->agent_id)->toBeNull();
});

test('HC-03: claim sobre conversación sin solicitud handoff devuelve conflicto', function (): void {
    ['tenant' => $tenant, 'agent_a' => $agent, 'conversation' => $conversation] = handoff_assignment_setup();
    $conversation->forceFill(['bot_paused' => true])->save();

    $this->actingAs($agent)
        ->postJson(handoff_assignment_url($tenant, $conversation, 'claim'))
        ->assertStatus(409)
        ->assertJsonPath('code', 'CONVERSATION_NOT_AWAITING_HANDOFF');
});

test('HC-04: claim con bot_paused false devuelve conflicto', function (): void {
    ['tenant' => $tenant, 'agent_a' => $agent, 'conversation' => $conversation] = handoff_assignment_setup();
    $conversation->forceFill(['handoff_requested_at' => now()])->save();

    $this->actingAs($agent)
        ->postJson(handoff_assignment_url($tenant, $conversation, 'claim'))
        ->assertStatus(409)
        ->assertJsonPath('code', 'CONVERSATION_NOT_AWAITING_HANDOFF');
});

test('HC-05: claim sobre conversación ya asignada devuelve 409', function (): void {
    ['tenant' => $tenant, 'owner' => $owner, 'agent_a' => $agentA, 'agent_b' => $agentB, 'conversation' => $conversation] = handoff_assignment_setup(handoff: true);
    $this->actingAs($owner)->postJson(handoff_assignment_url($tenant, $conversation, 'assign'), ['agent_id' => $agentA->id])->assertOk();

    $this->actingAs($agentB)
        ->postJson(handoff_assignment_url($tenant, $conversation, 'claim'))
        ->assertStatus(409)
        ->assertJsonPath('code', 'CONVERSATION_ALREADY_ASSIGNED');
});

test('HC-06: dos claims serializados producen un ganador y un conflicto', function (): void {
    ['tenant' => $tenant, 'agent_a' => $agentA, 'agent_b' => $agentB, 'conversation' => $conversation] = handoff_assignment_setup(handoff: true);
    $url = handoff_assignment_url($tenant, $conversation, 'claim');

    $this->actingAs($agentA)->postJson($url)->assertOk();
    $this->actingAs($agentB)->postJson($url)->assertStatus(409)->assertJsonPath('code', 'CONVERSATION_ALREADY_ASSIGNED');

    expect(ConversationAssignment::withoutTenantScope()->where('conversation_id', $conversation->id)->whereNull('unassigned_at')->count())->toBe(1);
});

test('HC-07: claim revalida membership activa dentro de la operación', function (): void {
    ['tenant' => $tenant, 'agent_a' => $agent, 'conversation' => $conversation] = handoff_assignment_setup(handoff: true);
    DB::table('tenant_users')->where('tenant_id', $tenant->id)->where('user_id', $agent->id)->update(['status' => 'disabled']);

    $this->actingAs($agent)
        ->postJson(handoff_assignment_url($tenant, $conversation, 'claim'))
        ->assertStatus(403)
        ->assertJsonPath('code', 'NO_TENANT');

    expect($conversation->fresh()?->agent_id)->toBeNull();
});

test('HC-08: tenant A no puede reclamar conversación de B', function (): void {
    ['tenant' => $tenantA, 'agent_a' => $agentA] = handoff_assignment_setup(handoff: true);
    ['conversation' => $conversationB] = handoff_assignment_setup(handoff: true);

    $this->actingAs($agentA)
        ->postJson(handoff_assignment_url($tenantA, $conversationB, 'claim'))
        ->assertNotFound();
});

test('HC-09: claim registra audit conversation.claimed sin datos sensibles', function (): void {
    ['tenant' => $tenant, 'agent_a' => $agent, 'conversation' => $conversation] = handoff_assignment_setup(handoff: true);
    $this->actingAs($agent)->postJson(handoff_assignment_url($tenant, $conversation, 'claim'))->assertOk();

    $audit = AuditLog::query()->where('action', 'conversation.claimed')->firstOrFail();
    $data = $audit->data;
    ksort($data);

    expect($audit->actor_user_id)->toBe($agent->id)
        ->and($data)->toBe([
            'agent_id' => $agent->id,
            'conversation_id' => $conversation->id,
            'previous_agent_id' => null,
            'reason' => 'claim',
        ]);
});

test('HC-10: claim crea assignment y participant con actor autenticado', function (): void {
    ['tenant' => $tenant, 'agent_a' => $agent, 'conversation' => $conversation] = handoff_assignment_setup(handoff: true);
    $this->actingAs($agent)->postJson(handoff_assignment_url($tenant, $conversation, 'claim'))->assertOk();

    $this->assertDatabaseHas('conversation_assignments', [
        'tenant_id' => $tenant->id,
        'conversation_id' => $conversation->id,
        'agent_id' => $agent->id,
        'assigned_by' => $agent->id,
        'reason' => 'claim',
        'unassigned_at' => null,
    ]);
    $this->assertDatabaseHas('conversation_participants', [
        'tenant_id' => $tenant->id,
        'conversation_id' => $conversation->id,
        'user_id' => $agent->id,
        'left_at' => null,
    ]);
});

test('HP-01: claim es capacidad explícita de owner admin y agent sin ampliar assign', function (): void {
    $owner = TenantPermission::permissionsForRole(UserRole::Owner);
    $admin = TenantPermission::permissionsForRole(UserRole::Admin);
    $agent = TenantPermission::permissionsForRole(UserRole::Agent);

    expect($owner)->toContain(TenantPermission::ClaimConversations)
        ->and($admin)->toContain(TenantPermission::ClaimConversations)
        ->and($agent)->toContain(TenantPermission::ClaimConversations)
        ->and($agent)->not->toContain(TenantPermission::AssignConversations);
});

test('HP-02: seeder materializa claim en el espejo spatie sin dar assign al agent', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $claimId = DB::table('permissions')->where('name', 'conversations.claim')->value('id');
    $assignId = DB::table('permissions')->where('name', 'conversations.assign')->value('id');

    expect($claimId)->not->toBeNull()
        ->and($assignId)->not->toBeNull();

    foreach (['owner', 'admin', 'agent'] as $roleName) {
        $roleId = DB::table('roles')->where('name', $roleName)->value('id');

        expect(DB::table('role_has_permissions')
            ->where('role_id', $roleId)
            ->where('permission_id', $claimId)
            ->exists())->toBeTrue();
    }

    $agentRoleId = DB::table('roles')->where('name', 'agent')->value('id');
    expect(DB::table('role_has_permissions')
        ->where('role_id', $agentRoleId)
        ->where('permission_id', $assignId)
        ->exists())->toBeFalse();
});

test('HMT-01: assignments de tenants A y B permanecen aisladas', function (): void {
    $setupA = handoff_assignment_setup();
    $setupB = handoff_assignment_setup();
    $this->actingAs($setupA['owner'])->postJson(handoff_assignment_url($setupA['tenant'], $setupA['conversation'], 'assign'), ['agent_id' => $setupA['agent_a']->id])->assertOk();
    $this->actingAs($setupB['owner'])->postJson(handoff_assignment_url($setupB['tenant'], $setupB['conversation'], 'assign'), ['agent_id' => $setupB['agent_a']->id])->assertOk();

    TenantContext::setId($setupA['tenant']->id);
    expect(ConversationAssignment::query()->pluck('tenant_id')->all())->toBe([$setupA['tenant']->id]);
    TenantContext::clear();
});

test('HMT-02: participants de tenants A y B permanecen aislados', function (): void {
    $setupA = handoff_assignment_setup(handoff: true);
    $setupB = handoff_assignment_setup(handoff: true);
    $this->actingAs($setupA['agent_a'])->postJson(handoff_assignment_url($setupA['tenant'], $setupA['conversation'], 'claim'))->assertOk();
    $this->actingAs($setupB['agent_a'])->postJson(handoff_assignment_url($setupB['tenant'], $setupB['conversation'], 'claim'))->assertOk();

    TenantContext::setId($setupB['tenant']->id);
    expect(ConversationParticipant::query()->pluck('tenant_id')->all())->toBe([$setupB['tenant']->id]);
    TenantContext::clear();
});

test('HMT-03: agente del tenant A no puede ser target en B', function (): void {
    $setupA = handoff_assignment_setup();
    $setupB = handoff_assignment_setup();

    $this->actingAs($setupB['owner'])
        ->postJson(handoff_assignment_url($setupB['tenant'], $setupB['conversation'], 'assign'), ['agent_id' => $setupA['agent_a']->id])
        ->assertStatus(422)
        ->assertJsonPath('code', 'AGENT_NOT_IN_TENANT');
});

test('HMT-04: users.id manipulado nunca salta la membresía activa', function (): void {
    ['tenant' => $tenant, 'owner' => $owner, 'conversation' => $conversation] = handoff_assignment_setup();
    $outsider = User::factory()->create();

    $this->actingAs($owner)
        ->postJson(handoff_assignment_url($tenant, $conversation, 'assign'), ['agent_id' => $outsider->id])
        ->assertStatus(422)
        ->assertJsonPath('code', 'AGENT_NOT_IN_TENANT');
});

test('HMT-05: tenant_id del body no altera assign y claim lo rechaza', function (): void {
    ['tenant' => $tenant, 'owner' => $owner, 'agent_a' => $agent, 'conversation' => $conversation] = handoff_assignment_setup();
    $otherTenant = Tenant::factory()->create();

    $this->actingAs($owner)
        ->postJson(handoff_assignment_url($tenant, $conversation, 'assign'), [
            'agent_id' => $agent->id,
            'tenant_id' => $otherTenant->id,
        ])
        ->assertOk();

    $handoff = make_conversation($tenant, make_contact($tenant), [
        'bot_paused' => true,
        'handoff_requested_at' => now(),
    ]);

    $this->actingAs($agent)
        ->postJson(handoff_assignment_url($tenant, $handoff, 'claim'), ['tenant_id' => $otherTenant->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('tenant_id');
});

test('HANDOFF-REALTIME-01: assignment emite ConversationUpdated con broadcast after commit', function (): void {
    Event::fake([ConversationUpdated::class]);
    ['tenant' => $tenant, 'owner' => $owner, 'agent_a' => $agent, 'conversation' => $conversation] = handoff_assignment_setup();

    $this->actingAs($owner)
        ->postJson(handoff_assignment_url($tenant, $conversation, 'assign'), ['agent_id' => $agent->id])
        ->assertOk();

    Event::assertDispatchedTimes(ConversationUpdated::class, 1);
    Event::assertDispatched(
        ConversationUpdated::class,
        fn (ConversationUpdated $event): bool => $event->afterCommit
            && $event->conversation->id === $conversation->id
            && $event->conversation->agent_id === $agent->id,
    );
});
