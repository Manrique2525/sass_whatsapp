<?php

declare(strict_types=1);

namespace Tests\Feature\Conversations;

use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Tests de scope/buckets y counts del inbox (FASE 15 U5).
 */
test('INBOX-01: scope all retorna todas las conversaciones del tenant', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    make_tenant_member($user, $tenant, 'owner');

    $contact = make_contact($tenant);

    make_conversation($tenant, $contact, ['status' => 'open']);
    make_conversation($tenant, $contact, ['status' => 'pending']);
    make_conversation($tenant, $contact, ['status' => 'open', 'agent_id' => $user->id]);

    $this->actingAs($user)
        ->getJson("/api/v1/tenants/{$tenant->id}/conversations?scope=all")
        ->assertOk()
        ->assertJsonCount(3, 'conversations')
        ->assertJsonPath('counts.all', 3)
        ->assertJsonPath('counts.mine', 1)
        ->assertJsonPath('counts.unassigned', 0);
});

test('INBOX-02: scope mine retorna solo conversaciones del usuario autenticado', function (): void {
    $tenant = Tenant::factory()->create();
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    make_tenant_member($userA, $tenant, 'owner');
    make_tenant_member($userB, $tenant, 'agent');

    $contact = make_contact($tenant);

    make_conversation($tenant, $contact, ['status' => 'open', 'agent_id' => $userA->id]);
    make_conversation($tenant, $contact, ['status' => 'open', 'agent_id' => $userB->id]);
    make_conversation($tenant, $contact, ['status' => 'open']);

    $this->actingAs($userA)
        ->getJson("/api/v1/tenants/{$tenant->id}/conversations?scope=mine")
        ->assertOk()
        ->assertJsonCount(1, 'conversations')
        ->assertJsonPath('counts.all', 3)
        ->assertJsonPath('counts.mine', 1);
});

test('INBOX-03: scope unassigned retorna solo conversaciones sin agente con handoff', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    make_tenant_member($user, $tenant, 'owner');

    $contact = make_contact($tenant);

    // Unassigned + handoff: should appear
    $handoff = make_conversation($tenant, $contact, [
        'status' => 'open',
        'bot_paused' => true,
    ]);
    $handoff->forceFill(['handoff_requested_at' => now()])->save();

    // Assigned: should not appear
    make_conversation($tenant, $contact, [
        'status' => 'open',
        'agent_id' => $user->id,
    ]);

    // Unassigned but no handoff: should not appear
    make_conversation($tenant, $contact, ['status' => 'open']);

    $this->actingAs($user)
        ->getJson("/api/v1/tenants/{$tenant->id}/conversations?scope=unassigned")
        ->assertOk()
        ->assertJsonCount(1, 'conversations')
        ->assertJsonPath('counts.unassigned', 1)
        ->assertJsonPath('counts.all', 3)
        ->assertJsonPath('counts.mine', 1);
});

test('INBOX-04: counts reflejan el estado real independientemente del scope activo', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    make_tenant_member($user, $tenant, 'owner');

    $contact = make_contact($tenant);

    make_conversation($tenant, $contact, ['status' => 'open', 'agent_id' => $user->id]);
    make_conversation($tenant, $contact, ['status' => 'open']);
    $handoffConv = make_conversation($tenant, $contact, ['status' => 'open', 'bot_paused' => true]);
    $handoffConv->forceFill(['handoff_requested_at' => now()])->save();

    $response = $this->actingAs($user)
        ->getJson("/api/v1/tenants/{$tenant->id}/conversations?scope=mine")
        ->assertOk();

    // Counts always reflect total, not filtered by scope
    $response->assertJsonPath('counts.all', 3)
        ->assertJsonPath('counts.mine', 1)
        ->assertJsonPath('counts.unassigned', 1);
});

test('INBOX-05: tenant A y B tienen counts aislados', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    make_tenant_member($userA, $tenantA, 'owner');
    make_tenant_member($userB, $tenantB, 'owner');

    $contactA = make_contact($tenantA);
    $contactB = make_contact($tenantB);

    make_conversation($tenantA, $contactA, ['status' => 'open']);
    make_conversation($tenantB, $contactB, ['status' => 'open']);
    make_conversation($tenantB, $contactB, ['status' => 'open']);

    $this->actingAs($userA)
        ->getJson("/api/v1/tenants/{$tenantA->id}/conversations")
        ->assertOk()
        ->assertJsonPath('counts.all', 1);

    $this->actingAs($userB)
        ->getJson("/api/v1/tenants/{$tenantB->id}/conversations")
        ->assertOk()
        ->assertJsonPath('counts.all', 2);
});

test('INBOX-06: agent_id filtra por users.id del agente', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    $agent = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');
    make_tenant_member($agent, $tenant, 'agent');

    $contact = make_contact($tenant);

    make_conversation($tenant, $contact, ['status' => 'open', 'agent_id' => $agent->id]);
    make_conversation($tenant, $contact, ['status' => 'open', 'agent_id' => $owner->id]);

    $this->actingAs($owner)
        ->getJson("/api/v1/tenants/{$tenant->id}/conversations?agent_id={$agent->id}")
        ->assertOk()
        ->assertJsonCount(1, 'conversations')
        ->assertJsonPath('conversations.0.agent.id', $agent->id);
});

test('INBOX-07: membresía inactiva deniega acceso al listado', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    make_tenant_member($user, $tenant, 'owner');

    // Deactivate membership
    $user->tenants()->wherePivot('tenant_id', $tenant->id)->updateExistingPivot($tenant->id, ['status' => 'inactive']);

    $this->actingAs($user)
        ->getJson("/api/v1/tenants/{$tenant->id}/conversations")
        ->assertStatus(403);
});

test('INBOX-08: paginación funciona con scope y counts se mantienen consistentes', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    make_tenant_member($user, $tenant, 'owner');

    $contact = make_contact($tenant);

    for ($i = 0; $i < 5; $i++) {
        make_conversation($tenant, $contact, ['status' => 'open']);
    }

    $response = $this->actingAs($user)
        ->getJson("/api/v1/tenants/{$tenant->id}/conversations?scope=all&per_page=2")
        ->assertOk();

    $response->assertJsonCount(2, 'conversations')
        ->assertJsonPath('meta.total', 5)
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.last_page', 3)
        ->assertJsonPath('counts.all', 5);
});
