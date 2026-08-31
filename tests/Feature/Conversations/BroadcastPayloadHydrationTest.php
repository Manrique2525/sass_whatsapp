<?php

declare(strict_types=1);

use App\Domain\Conversations\Enums\ConversationStatus;
use App\Domain\Conversations\Enums\InboxConversationChangeKind;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Events\ConversationUpdated;
use App\Events\InboxConversationChanged;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function broadcast_payload_setup(): array
{
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    $agent = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');
    make_tenant_member($agent, $tenant, 'agent');
    $contact = make_contact($tenant);
    $conversation = make_conversation($tenant, $contact, [
        'status' => ConversationStatus::Open,
    ]);

    return compact('tenant', 'agent', 'contact', 'conversation');
}

function assign_broadcast_conversation(Conversation $conversation, User $agent): void
{
    $conversation->forceFill([
        'agent_id' => $agent->id,
        'auto_assigned' => false,
    ])->save();

    TenantContext::withId($conversation->tenant_id, function () use ($conversation, $agent): void {
        $conversation->assignments()->create([
            'agent_id' => $agent->id,
            'assigned_by' => $agent->id,
            'assigned_at' => now(),
            'reason' => 'claim',
        ]);
    });
}

test('broadcast payload hydrates current agent and contact after serialization', function (): void {
    ['agent' => $agent, 'contact' => $contact, 'conversation' => $conversation] = broadcast_payload_setup();
    assign_broadcast_conversation($conversation, $agent);

    $event = new InboxConversationChanged($conversation, InboxConversationChangeKind::Claimed);
    /** @var InboxConversationChanged $restored */
    $restored = unserialize(serialize($event));
    $payload = $restored->broadcastWith();

    expect($payload['conversation']['agent'])
        ->toMatchArray(['id' => $agent->id, 'name' => $agent->name, 'email' => $agent->email])
        ->and($payload['conversation']['contact']['id'])->toBe($contact->id)
        ->and($payload['kind'])->toBe('claimed');
});

test('conversation updated payload hydrates current agent and contact after serialization', function (): void {
    ['agent' => $agent, 'contact' => $contact, 'conversation' => $conversation] = broadcast_payload_setup();
    assign_broadcast_conversation($conversation, $agent);

    $event = new ConversationUpdated($conversation);
    /** @var ConversationUpdated $restored */
    $restored = unserialize(serialize($event));
    $payload = $restored->broadcastWith();

    expect($payload['conversation']['agent'])
        ->toMatchArray(['id' => $agent->id, 'name' => $agent->name, 'email' => $agent->email])
        ->and($payload['conversation']['contact']['id'])->toBe($contact->id);
});
