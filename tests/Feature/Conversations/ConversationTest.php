<?php

declare(strict_types=1);

use App\Application\Conversations\Services\ConversationService;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Conversations\Enums\ConversationStatus;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\TenantPermission;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| FASE 8 — CONVERSACIONES (inbox)
|--------------------------------------------------------------------------
*/

function conversation_url(Tenant $tenant, ?string $conversationId = null): string
{
    $base = '/api/v1/tenants/'.$tenant->id.'/conversations';

    return $conversationId === null ? $base : $base.'/'.$conversationId;
}

function make_conversation(Tenant $tenant, Contact $contact, array $attributes = []): Conversation
{
    TenantContext::setId($tenant->id);

    try {
        return Conversation::query()->create(array_merge([
            'contact_id' => $contact->id,
            'status' => 'open',
        ], $attributes));
    } finally {
        TenantContext::clear();
    }
}

test('CONV-1: crear una conversación para un contacto del tenant devuelve 201', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $contact = make_contact($tenant, ['name' => 'Ana García']);

    $this->actingAs($owner)
        ->postJson(conversation_url($tenant), ['contact_id' => $contact->id])
        ->assertStatus(201)
        ->assertJsonPath('conversation.status', 'open')
        ->assertJsonPath('conversation.contact.id', $contact->id)
        ->assertJsonPath('conversation.bot_paused', false)
        ->assertJsonPath('conversation.agent', null);

    $this->assertDatabaseHas('conversations', [
        'tenant_id' => $tenant->id,
        'contact_id' => $contact->id,
        'status' => 'open',
    ]);
});

test('CONV-2: crear valida contact_id (uuid) y status (enum)', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $this->actingAs($owner)
        ->postJson(conversation_url($tenant), [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('contact_id');

    $this->actingAs($owner)
        ->postJson(conversation_url($tenant), ['contact_id' => 'no-es-uuid'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('contact_id');

    $contact = make_contact($tenant);

    $this->actingAs($owner)
        ->postJson(conversation_url($tenant), ['contact_id' => $contact->id, 'status' => 'inexistente'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');
});

test('CONV-3: crear con un contacto inexistente devuelve 404 (oculta existencia)', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $this->actingAs($owner)
        ->postJson(conversation_url($tenant), ['contact_id' => (string) Str::uuid()])
        ->assertStatus(404);
});

test('CONV-4: index pagina y filtra por status y search sobre el contacto', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $ana = make_contact($tenant, ['name' => 'Ana García', 'phone' => '+541155554444']);
    $bruno = make_contact($tenant, ['name' => 'Bruno López', 'phone' => '+5491155553333']);

    make_conversation($tenant, $ana, ['status' => 'open']);
    make_conversation($tenant, $bruno, ['status' => 'resolved']);

    $this->actingAs($owner)
        ->getJson(conversation_url($tenant))
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonCount(2, 'conversations');

    $this->actingAs($owner)
        ->getJson(conversation_url($tenant).'?search=ana')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('conversations.0.contact.name', 'Ana García');

    $this->actingAs($owner)
        ->getJson(conversation_url($tenant).'?status=resolved')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('conversations.0.status', 'resolved');

    $this->actingAs($owner)
        ->getJson(conversation_url($tenant).'?status=invalido')
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');
});

test('CONV-5: index filtra por agent_id', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $agentA = User::factory()->create();
    make_tenant_member($agentA, $tenant, 'agent');
    $agentB = User::factory()->create();
    make_tenant_member($agentB, $tenant, 'agent');

    $contact = make_contact($tenant);
    make_conversation($tenant, $contact, ['agent_id' => $agentA->id]);
    make_conversation($tenant, $contact, ['agent_id' => $agentB->id]);

    $this->actingAs($owner)
        ->getJson(conversation_url($tenant).'?agent_id='.$agentA->id)
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('conversations.0.agent.id', $agentA->id);
});

test('CONV-6: show devuelve la conversación con contacto, agente y detalle', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $agent = User::factory()->create();
    make_tenant_member($agent, $tenant, 'agent');
    $contact = make_contact($tenant, ['name' => 'Ana García']);
    $conversation = make_conversation($tenant, $contact, ['agent_id' => $agent->id]);

    $this->actingAs($owner)
        ->getJson(conversation_url($tenant, $conversation->id))
        ->assertOk()
        ->assertJsonPath('conversation.id', $conversation->id)
        ->assertJsonPath('conversation.contact.name', 'Ana García')
        ->assertJsonPath('conversation.agent.id', $agent->id)
        ->assertJsonPath('conversation.status', 'open');
});

test('CONV-7: update cambia el estado respetando la máquina de estados', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $conversation = make_conversation($tenant, make_contact($tenant));

    $this->actingAs($owner)
        ->patchJson(conversation_url($tenant, $conversation->id), ['status' => 'pending'])
        ->assertOk()
        ->assertJsonPath('conversation.status', 'pending');

    $this->actingAs($owner)
        ->patchJson(conversation_url($tenant, $conversation->id), ['status' => 'resolved'])
        ->assertOk()
        ->assertJsonPath('conversation.status', 'resolved');
});

test('CONV-8: update con transición inválida devuelve 409 CONVERSATION_INVALID_STATE', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $conversation = make_conversation($tenant, make_contact($tenant));

    // open → archived no está permitido (debe pasar por resolved).
    $this->actingAs($owner)
        ->patchJson(conversation_url($tenant, $conversation->id), ['status' => 'archived'])
        ->assertStatus(409)
        ->assertJson(['code' => 'CONVERSATION_INVALID_STATE']);

    $this->assertDatabaseHas('conversations', ['id' => $conversation->id, 'status' => 'open']);
});

test('CONV-9: update con el mismo estado es no-op y no audita', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $conversation = make_conversation($tenant, make_contact($tenant));

    $this->actingAs($owner)
        ->patchJson(conversation_url($tenant, $conversation->id), ['status' => 'open'])
        ->assertOk()
        ->assertJsonPath('conversation.status', 'open');

    $this->assertDatabaseCount('audit_logs', 0);
});

test('CONV-10: update fusiona context por claves', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $conversation = make_conversation($tenant, make_contact($tenant), [
        'context' => ['flow_id' => 'f1', 'step' => 1],
    ]);

    $this->actingAs($owner)
        ->patchJson(conversation_url($tenant, $conversation->id), ['context' => ['step' => 2, 'extra' => true]])
        ->assertOk();

    $this->assertDatabaseHas('conversations', [
        'id' => $conversation->id,
        'context' => json_encode(['flow_id' => 'f1', 'step' => 2, 'extra' => true]),
    ]);
});

test('CONV-11: assign asigna a un agente activo y queda auditado', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $agent = User::factory()->create();
    make_tenant_member($agent, $tenant, 'agent');

    $conversation = make_conversation($tenant, make_contact($tenant));

    $this->actingAs($owner)
        ->postJson(conversation_url($tenant, $conversation->id).'/assign', ['agent_id' => $agent->id])
        ->assertOk()
        ->assertJsonPath('conversation.agent.id', $agent->id)
        ->assertJsonPath('conversation.auto_assigned', false);

    $this->assertDatabaseHas('conversations', ['id' => $conversation->id, 'agent_id' => $agent->id]);
    $this->assertDatabaseHas('conversation_assignments', [
        'conversation_id' => $conversation->id,
        'agent_id' => $agent->id,
        'assigned_by' => $owner->id,
        'reason' => 'manual',
    ]);
    $this->assertDatabaseHas('conversation_participants', [
        'conversation_id' => $conversation->id,
        'user_id' => $agent->id,
        'role' => 'agent',
        'left_at' => null,
    ]);
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'conversation.assigned',
        'tenant_id' => $tenant->id,
        'actor_user_id' => $owner->id,
        'subject_type' => Conversation::class,
        'subject_id' => $conversation->id,
    ]);
});

test('CONV-12: assign a un usuario no miembro activo devuelve 422 AGENT_NOT_IN_TENANT', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $other = Tenant::factory()->create();
    $stranger = User::factory()->create();
    make_tenant_member($stranger, $other, 'owner');

    $conversation = make_conversation($tenant, make_contact($tenant));

    $this->actingAs($owner)
        ->postJson(conversation_url($tenant, $conversation->id).'/assign', ['agent_id' => $stranger->id])
        ->assertStatus(422)
        ->assertJson(['code' => 'AGENT_NOT_IN_TENANT']);

    $this->assertDatabaseHas('conversations', ['id' => $conversation->id, 'agent_id' => null]);
    $this->assertDatabaseCount('conversation_assignments', 0);
});

test('CONV-13: transfer cierra la asignación anterior y crea la nueva', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $agentA = User::factory()->create();
    make_tenant_member($agentA, $tenant, 'agent');
    $agentB = User::factory()->create();
    make_tenant_member($agentB, $tenant, 'agent');

    $conversation = make_conversation($tenant, make_contact($tenant));

    $this->actingAs($owner)
        ->postJson(conversation_url($tenant, $conversation->id).'/assign', ['agent_id' => $agentA->id])
        ->assertOk();

    $this->actingAs($owner)
        ->postJson(conversation_url($tenant, $conversation->id).'/transfer', ['agent_id' => $agentB->id])
        ->assertOk()
        ->assertJsonPath('conversation.agent.id', $agentB->id);

    $this->assertDatabaseHas('conversations', ['id' => $conversation->id, 'agent_id' => $agentB->id]);
    $this->assertDatabaseCount('conversation_assignments', 2);

    $first = DB::table('conversation_assignments')
        ->where('conversation_id', $conversation->id)
        ->where('agent_id', $agentA->id)
        ->first();
    $this->assertNotNull($first->unassigned_at);

    $this->assertDatabaseHas('conversation_assignments', [
        'conversation_id' => $conversation->id,
        'agent_id' => $agentB->id,
        'reason' => 'transfer',
    ]);

    $previous = DB::table('conversation_participants')
        ->where('conversation_id', $conversation->id)
        ->where('user_id', $agentA->id)
        ->first();
    $this->assertNotNull($previous->left_at);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'conversation.transferred',
        'tenant_id' => $tenant->id,
        'subject_id' => $conversation->id,
    ]);
});

test('CONV-14: close resuelve una conversación abierta o pendiente', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $conversation = make_conversation($tenant, make_contact($tenant));

    $this->actingAs($owner)
        ->postJson(conversation_url($tenant, $conversation->id).'/close')
        ->assertOk()
        ->assertJsonPath('conversation.status', 'resolved');

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'conversation.closed',
        'tenant_id' => $tenant->id,
        'subject_id' => $conversation->id,
    ]);
});

test('CONV-15: close sobre archivada es 409 y sobre resuelta es no-op', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $archived = make_conversation($tenant, make_contact($tenant), ['status' => 'archived']);

    $this->actingAs($owner)
        ->postJson(conversation_url($tenant, $archived->id).'/close')
        ->assertStatus(409)
        ->assertJson(['code' => 'CONVERSATION_INVALID_STATE']);

    $resolved = make_conversation($tenant, make_contact($tenant), ['status' => 'resolved']);

    $this->actingAs($owner)
        ->postJson(conversation_url($tenant, $resolved->id).'/close')
        ->assertOk()
        ->assertJsonPath('conversation.status', 'resolved');

    $this->assertDatabaseCount('audit_logs', 0);
});

test('CONV-16: reopen reabre una conversación resuelta o archivada', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $resolved = make_conversation($tenant, make_contact($tenant), ['status' => 'resolved']);
    $archived = make_conversation($tenant, make_contact($tenant), ['status' => 'archived']);

    $this->actingAs($owner)
        ->postJson(conversation_url($tenant, $resolved->id).'/reopen')
        ->assertOk()
        ->assertJsonPath('conversation.status', 'open');

    $this->actingAs($owner)
        ->postJson(conversation_url($tenant, $archived->id).'/reopen')
        ->assertOk()
        ->assertJsonPath('conversation.status', 'open');

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'conversation.reopened',
        'tenant_id' => $tenant->id,
        'subject_id' => $resolved->id,
    ]);
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'conversation.reopened',
        'tenant_id' => $tenant->id,
        'subject_id' => $archived->id,
    ]);
});

test('CONV-17: pause-bot y resume-bot quedan auditados', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $conversation = make_conversation($tenant, make_contact($tenant));

    $this->actingAs($owner)
        ->postJson(conversation_url($tenant, $conversation->id).'/pause-bot')
        ->assertOk()
        ->assertJsonPath('conversation.bot_paused', true);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'conversation.bot_paused',
        'tenant_id' => $tenant->id,
        'subject_id' => $conversation->id,
    ]);

    $this->actingAs($owner)
        ->postJson(conversation_url($tenant, $conversation->id).'/resume-bot')
        ->assertOk()
        ->assertJsonPath('conversation.bot_paused', false);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'conversation.bot_resumed',
        'tenant_id' => $tenant->id,
        'subject_id' => $conversation->id,
    ]);
});

test('CRITICO CONV-18: Tenant A jamás crea conversaciones para un contacto de Tenant B', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    make_tenant_member($ownerA, $tenantA, 'owner');
    make_tenant_member($ownerB, $tenantB, 'owner');

    $contactB = make_contact($tenantB, ['name' => 'Cliente de B']);

    $this->actingAs($ownerA)
        ->postJson(conversation_url($tenantA), ['contact_id' => $contactB->id])
        ->assertStatus(404);

    $this->assertDatabaseCount('conversations', 0);
});

test('CRITICO CONV-19: Tenant A jamás lee ni modifica conversaciones de Tenant B, ni asigna usuarios de B', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    $agentB = User::factory()->create();
    make_tenant_member($ownerA, $tenantA, 'owner');
    make_tenant_member($ownerB, $tenantB, 'owner');
    make_tenant_member($agentB, $tenantB, 'agent');

    $contactB = make_contact($tenantB);
    $conversationB = make_conversation($tenantB, $contactB, ['status' => 'resolved']);

    $this->actingAs($ownerA)
        ->getJson(conversation_url($tenantA, $conversationB->id))
        ->assertStatus(404);

    $this->actingAs($ownerA)
        ->patchJson(conversation_url($tenantA, $conversationB->id), ['status' => 'open'])
        ->assertStatus(404);

    $this->actingAs($ownerA)
        ->postJson(conversation_url($tenantA, $conversationB->id).'/close')
        ->assertStatus(404);

    // A no puede ver las conversaciones de B desde el listado de B.
    $this->actingAs($ownerA)
        ->getJson(conversation_url($tenantB))
        ->assertStatus(404);

    // A no asigna una conversación propia a un agente de B.
    $contactA = make_contact($tenantA);
    $conversationA = make_conversation($tenantA, $contactA);

    $this->actingAs($ownerA)
        ->postJson(conversation_url($tenantA, $conversationA->id).'/assign', ['agent_id' => $agentB->id])
        ->assertStatus(422)
        ->assertJson(['code' => 'AGENT_NOT_IN_TENANT']);

    $this->assertDatabaseHas('conversations', [
        'id' => $conversationB->id,
        'status' => 'resolved',
        'tenant_id' => $tenantB->id,
    ]);
});

test('CONV-20: un tenant_id enviado en el cuerpo es ignorado', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $owner = User::factory()->create();
    make_tenant_member($owner, $tenantA, 'owner');

    $contactA = make_contact($tenantA);

    $this->actingAs($owner)
        ->postJson(conversation_url($tenantA), [
            'contact_id' => $contactA->id,
            'tenant_id' => $tenantB->id,
        ])
        ->assertStatus(201);

    $this->assertDatabaseHas('conversations', ['tenant_id' => $tenantA->id, 'contact_id' => $contactA->id]);
    $this->assertDatabaseMissing('conversations', ['tenant_id' => $tenantB->id]);
});

test('CONV-21: la matriz concede conversations.view a todos y manage/assign solo a owner/admin', function (): void {
    $ownerPerms = TenantPermission::permissionsForRole(UserRole::Owner);
    $adminPerms = TenantPermission::permissionsForRole(UserRole::Admin);
    $agentPerms = TenantPermission::permissionsForRole(UserRole::Agent);

    expect($ownerPerms)->toContain(TenantPermission::ViewConversations)
        ->and($ownerPerms)->toContain(TenantPermission::ManageConversations)
        ->and($ownerPerms)->toContain(TenantPermission::AssignConversations)
        ->and($adminPerms)->toContain(TenantPermission::ViewConversations)
        ->and($adminPerms)->toContain(TenantPermission::ManageConversations)
        ->and($adminPerms)->toContain(TenantPermission::AssignConversations)
        ->and($agentPerms)->toContain(TenantPermission::ViewConversations)
        ->and($agentPerms)->not->toContain(TenantPermission::ManageConversations)
        ->and($agentPerms)->not->toContain(TenantPermission::AssignConversations);
});

test('CONV-22: el agente ve conversaciones pero NO puede crear, modificar, cerrar ni asignar', function (): void {
    $tenant = Tenant::factory()->create();
    $agent = User::factory()->create();
    make_tenant_member($agent, $tenant, 'agent');

    $contact = make_contact($tenant);
    $conversation = make_conversation($tenant, $contact);

    $this->actingAs($agent)->getJson(conversation_url($tenant))->assertOk();
    $this->actingAs($agent)->getJson(conversation_url($tenant, $conversation->id))->assertOk();

    $this->actingAs($agent)
        ->postJson(conversation_url($tenant), ['contact_id' => $contact->id])
        ->assertStatus(403)
        ->assertJson(['code' => 'PERMISSION_DENIED']);

    $this->actingAs($agent)
        ->patchJson(conversation_url($tenant, $conversation->id), ['status' => 'pending'])
        ->assertStatus(403)
        ->assertJson(['code' => 'PERMISSION_DENIED']);

    $this->actingAs($agent)
        ->postJson(conversation_url($tenant, $conversation->id).'/close')
        ->assertStatus(403)
        ->assertJson(['code' => 'PERMISSION_DENIED']);

    $this->actingAs($agent)
        ->postJson(conversation_url($tenant, $conversation->id).'/assign', ['agent_id' => 1])
        ->assertStatus(403)
        ->assertJson(['code' => 'PERMISSION_DENIED']);

    $this->assertDatabaseHas('conversations', ['id' => $conversation->id, 'status' => 'open']);
});

test('CONV-23: un no-miembro del tenant recibe 404', function (): void {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $stranger = User::factory()->create();
    make_tenant_member($stranger, $other, 'owner');

    $conversation = make_conversation($tenant, make_contact($tenant));

    $this->actingAs($stranger)
        ->getJson(conversation_url($tenant, $conversation->id))
        ->assertStatus(404);
});

test('CONV-24: el soft delete oculta la conversación y findOrCreateActiveForContact la reutiliza o crea', function (): void {
    $tenant = Tenant::factory()->create();
    $contact = make_contact($tenant);
    $service = app(ConversationService::class);

    $conversation = make_conversation($tenant, $contact);

    $conversation->delete();

    $this->assertSoftDeleted('conversations', ['id' => $conversation->id]);

    // Tras el soft delete, el servicio crea una conversación nueva.
    $recreated = $service->findOrCreateActiveForContact($tenant, $contact->id);

    expect($recreated->id)->not->toBe($conversation->id)
        ->and($recreated->tenant_id)->toBe($tenant->id)
        ->and($recreated->status)->toBe(ConversationStatus::Open);

    // Y la reutiliza si ya existe una activa.
    $again = $service->findOrCreateActiveForContact($tenant, $contact->id);

    expect($again->id)->toBe($recreated->id);

    $this->assertDatabaseCount('conversations', 2);
    expect(TenantContext::id())->toBeNull();
});
