<?php

declare(strict_types=1);

use App\Domain\Conversations\Models\Conversation;
use App\Domain\Conversations\Models\ConversationAssignment;
use App\Domain\Conversations\Models\ConversationParticipant;
use App\Domain\Messages\Enums\MessageDirection;
use App\Domain\Messages\Enums\MessageStatus;
use App\Domain\Messages\Enums\MessageType;
use App\Domain\Messages\Models\Message;
use App\Domain\Tenants\Exceptions\TenantContextMissingException;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * @return array{tenant: Tenant, conversation: Conversation, agent: User}
 */
function handoff_data_setup(): array
{
    $tenant = Tenant::factory()->create();
    $agent = User::factory()->create();
    make_tenant_member($agent, $tenant, 'agent');
    $contact = make_contact($tenant);
    $conversation = make_conversation($tenant, $contact);

    return compact('tenant', 'conversation', 'agent');
}

function create_open_assignment(Tenant $tenant, Conversation $conversation, User $agent): ConversationAssignment
{
    return TenantContext::withId($tenant->id, fn (): ConversationAssignment => ConversationAssignment::query()->create([
        'conversation_id' => $conversation->id,
        'agent_id' => $agent->id,
        'assigned_at' => now(),
        'reason' => 'manual',
    ]));
}

test('HANDOFF-DATA-01: una assignment abierta funciona y recibe tenant_id del contexto', function (): void {
    ['tenant' => $tenant, 'conversation' => $conversation, 'agent' => $agent] = handoff_data_setup();

    $assignment = create_open_assignment($tenant, $conversation, $agent);

    expect($assignment->tenant_id)->toBe($tenant->id)
        ->and($assignment->tenant->is($tenant))->toBeTrue()
        ->and($assignment->unassigned_at)->toBeNull();
});

test('HANDOFF-DATA-02: una segunda assignment abierta para la misma conversación falla en DB', function (): void {
    ['tenant' => $tenant, 'conversation' => $conversation, 'agent' => $agent] = handoff_data_setup();
    $otherAgent = User::factory()->create();
    make_tenant_member($otherAgent, $tenant, 'agent');

    create_open_assignment($tenant, $conversation, $agent);

    expect(fn (): ConversationAssignment => create_open_assignment($tenant, $conversation, $otherAgent))
        ->toThrow(QueryException::class);
});

test('HANDOFF-DATA-03: después de cerrar la primera puede abrirse otra assignment', function (): void {
    ['tenant' => $tenant, 'conversation' => $conversation, 'agent' => $agent] = handoff_data_setup();
    $otherAgent = User::factory()->create();
    make_tenant_member($otherAgent, $tenant, 'agent');

    $first = create_open_assignment($tenant, $conversation, $agent);
    $first->forceFill(['unassigned_at' => now()])->save();
    $second = create_open_assignment($tenant, $conversation, $otherAgent);

    expect($second->unassigned_at)->toBeNull()
        ->and(ConversationAssignment::withoutTenantScope()
            ->where('conversation_id', $conversation->id)
            ->whereNull('unassigned_at')
            ->count())->toBe(1);
});

test('HANDOFF-DATA-04: conversaciones diferentes pueden tener assignment abierta', function (): void {
    ['tenant' => $tenant, 'conversation' => $firstConversation, 'agent' => $agent] = handoff_data_setup();
    $secondConversation = make_conversation($tenant, make_contact($tenant));

    create_open_assignment($tenant, $firstConversation, $agent);
    create_open_assignment($tenant, $secondConversation, $agent);

    expect(ConversationAssignment::withoutTenantScope()->whereNull('unassigned_at')->count())->toBe(2);
});

test('HANDOFF-DATA-05: down elimina invariantes y up restaura columnas con backfill', function (): void {
    ['tenant' => $tenant, 'conversation' => $conversation, 'agent' => $agent] = handoff_data_setup();
    $migration = require database_path('migrations/2026_08_18_010000_establish_handoff_data_invariants.php');

    $migration->down();

    expect(Schema::hasColumn('conversation_assignments', 'tenant_id'))->toBeFalse()
        ->and(Schema::hasColumn('conversation_participants', 'tenant_id'))->toBeFalse()
        ->and(Schema::hasColumn('messages', 'sent_by_user_id'))->toBeFalse()
        ->and(Schema::hasColumn('conversations', 'handoff_requested_at'))->toBeFalse();

    $now = now();
    DB::table('conversation_assignments')->insert([
        'conversation_id' => $conversation->id,
        'agent_id' => $agent->id,
        'assigned_at' => $now,
        'reason' => 'manual',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('conversation_participants')->insert([
        'conversation_id' => $conversation->id,
        'user_id' => $agent->id,
        'role' => 'agent',
        'joined_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $migration->up();

    expect(Schema::hasColumn('conversation_assignments', 'tenant_id'))->toBeTrue()
        ->and(Schema::hasColumn('conversation_participants', 'tenant_id'))->toBeTrue()
        ->and(Schema::hasColumn('messages', 'sent_by_user_id'))->toBeTrue()
        ->and(Schema::hasColumn('conversations', 'handoff_requested_at'))->toBeTrue()
        ->and(DB::table('conversation_assignments')->value('tenant_id'))->toBe($tenant->id)
        ->and(DB::table('conversation_participants')->value('tenant_id'))->toBe($tenant->id);
});

test('HANDOFF-TENANT-01: assignments se aíslan automáticamente por TenantContext', function (): void {
    ['tenant' => $tenantA, 'conversation' => $conversationA, 'agent' => $agentA] = handoff_data_setup();
    ['tenant' => $tenantB, 'conversation' => $conversationB, 'agent' => $agentB] = handoff_data_setup();
    create_open_assignment($tenantA, $conversationA, $agentA);
    create_open_assignment($tenantB, $conversationB, $agentB);

    TenantContext::setId($tenantA->id);
    expect(ConversationAssignment::query()->pluck('tenant_id')->all())->toBe([$tenantA->id]);

    TenantContext::setId($tenantB->id);
    expect(ConversationAssignment::query()->pluck('tenant_id')->all())->toBe([$tenantB->id]);

    TenantContext::clear();
    expect(ConversationAssignment::query()->count())->toBe(0)
        ->and(ConversationAssignment::withoutTenantScope()->count())->toBe(2);
});

test('HANDOFF-TENANT-02: participants se aíslan y tenant_id no es mass assignable', function (): void {
    ['tenant' => $tenantA, 'conversation' => $conversationA, 'agent' => $agentA] = handoff_data_setup();
    ['tenant' => $tenantB, 'conversation' => $conversationB, 'agent' => $agentB] = handoff_data_setup();

    $participantA = TenantContext::withId($tenantA->id, fn (): ConversationParticipant => ConversationParticipant::query()->create([
        'tenant_id' => $tenantB->id,
        'conversation_id' => $conversationA->id,
        'user_id' => $agentA->id,
        'role' => 'agent',
        'joined_at' => now(),
    ]));
    TenantContext::withId($tenantB->id, fn (): ConversationParticipant => ConversationParticipant::query()->create([
        'conversation_id' => $conversationB->id,
        'user_id' => $agentB->id,
        'role' => 'agent',
        'joined_at' => now(),
    ]));

    expect($participantA->tenant_id)->toBe($tenantA->id);

    TenantContext::setId($tenantA->id);
    expect(ConversationParticipant::query()->pluck('tenant_id')->all())->toBe([$tenantA->id]);

    TenantContext::clear();
    expect(ConversationParticipant::query()->count())->toBe(0)
        ->and(ConversationParticipant::withoutTenantScope()->count())->toBe(2);
});

test('HANDOFF-TENANT-03: crear assignment o participant sin contexto falla seguro', function (): void {
    ['conversation' => $conversation, 'agent' => $agent] = handoff_data_setup();
    TenantContext::clear();

    expect(fn () => ConversationAssignment::query()->create([
        'conversation_id' => $conversation->id,
        'agent_id' => $agent->id,
        'assigned_at' => now(),
        'reason' => 'manual',
    ]))->toThrow(TenantContextMissingException::class);

    expect(fn () => ConversationParticipant::query()->create([
        'conversation_id' => $conversation->id,
        'user_id' => $agent->id,
        'role' => 'agent',
    ]))->toThrow(TenantContextMissingException::class);
});

test('HANDOFF-TENANT-04: DB rechaza assignment cuyo tenant no coincide con la conversación', function (): void {
    ['conversation' => $conversationA] = handoff_data_setup();
    ['tenant' => $tenantB, 'agent' => $agentB] = handoff_data_setup();
    $now = now();

    expect(fn () => DB::table('conversation_assignments')->insert([
        'tenant_id' => $tenantB->id,
        'conversation_id' => $conversationA->id,
        'agent_id' => $agentB->id,
        'assigned_at' => $now,
        'reason' => 'manual',
        'created_at' => $now,
        'updated_at' => $now,
    ]))->toThrow(QueryException::class);
});

test('HANDOFF-TENANT-05: DB rechaza participant cuyo tenant no coincide con la conversación', function (): void {
    ['conversation' => $conversationA] = handoff_data_setup();
    ['tenant' => $tenantB, 'agent' => $agentB] = handoff_data_setup();
    $now = now();

    expect(fn () => DB::table('conversation_participants')->insert([
        'tenant_id' => $tenantB->id,
        'conversation_id' => $conversationA->id,
        'user_id' => $agentB->id,
        'role' => 'agent',
        'joined_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]))->toThrow(QueryException::class);
});

test('HANDOFF-DATA-06: handoff_requested_at persiste con cast datetime', function (): void {
    ['tenant' => $tenant, 'conversation' => $conversation] = handoff_data_setup();
    $requestedAt = now()->startOfSecond();

    $conversation->forceFill(['handoff_requested_at' => $requestedAt])->save();
    $fresh = TenantContext::withId($tenant->id, fn (): ?Conversation => Conversation::query()->find($conversation->id));

    expect($fresh?->handoff_requested_at)->toBeInstanceOf(Carbon::class)
        ->and($fresh?->handoff_requested_at?->equalTo($requestedAt))->toBeTrue();
});

test('HANDOFF-DATA-07: sent_by_user_id acepta null y un usuario válido', function (): void {
    ['tenant' => $tenant, 'conversation' => $conversation, 'agent' => $agent] = handoff_data_setup();

    $message = TenantContext::withId($tenant->id, fn (): Message => Message::query()->create([
        'conversation_id' => $conversation->id,
        'direction' => MessageDirection::Outbound,
        'type' => MessageType::Text,
        'status' => MessageStatus::Pending,
        'body' => 'Mensaje manual futuro',
    ]));

    expect($message->sent_by_user_id)->toBeNull()
        ->and($message->sentByUser)->toBeNull();

    $message->forceFill(['sent_by_user_id' => $agent->id])->save();
    $message->refresh();

    expect($message->sent_by_user_id)->toBe($agent->id)
        ->and($message->sentByUser->is($agent))->toBeTrue();

    $agent->delete();
    $message->refresh();

    expect($message->sent_by_user_id)->toBeNull();
});

test('HANDOFF-DATA-08: sent_by_user_id inválido es rechazado por la FK', function (): void {
    ['tenant' => $tenant, 'conversation' => $conversation] = handoff_data_setup();

    expect(function () use ($tenant, $conversation): void {
        TenantContext::withId($tenant->id, function () use ($conversation): void {
            $message = Message::query()->create([
                'conversation_id' => $conversation->id,
                'direction' => MessageDirection::Outbound,
                'type' => MessageType::Text,
                'status' => MessageStatus::Pending,
                'body' => 'Actor inválido',
            ]);
            $message->forceFill(['sent_by_user_id' => PHP_INT_MAX])->save();
        });
    })->toThrow(QueryException::class);
});

test('HANDOFF-DATA-09: sent_by_user_id no es aceptado por mass assignment', function (): void {
    ['tenant' => $tenant, 'conversation' => $conversation, 'agent' => $agent] = handoff_data_setup();

    $message = TenantContext::withId($tenant->id, fn (): Message => Message::query()->create([
        'conversation_id' => $conversation->id,
        'sent_by_user_id' => $agent->id,
        'direction' => MessageDirection::Outbound,
        'type' => MessageType::Text,
        'status' => MessageStatus::Pending,
        'body' => 'No confiar en payload',
    ]));

    expect($message->sent_by_user_id)->toBeNull();
});
