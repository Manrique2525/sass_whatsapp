<?php

declare(strict_types=1);

use App\Application\Messages\Services\MessageOriginClassifier;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Messages\Enums\MessageOrigin;
use App\Domain\Messages\Models\Message;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\TenantMembershipStatus;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
});

/*
|--------------------------------------------------------------------------
| MessageOriginClassifier Tests (FASE 29 U2)
|--------------------------------------------------------------------------
|
| F29-U2-ORIGIN-01..09 — All classification branches.
| Covers: automation, human, handoff, unknown origin, edge cases.
|
*/

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->contact = make_contact($this->tenant);
    $this->conversation = make_conversation($this->tenant, $this->contact);
    TenantContext::setId($this->tenant->id);
    $this->classifier = app(MessageOriginClassifier::class);
});

it('F29-U2-ORIGIN-01: automation origin returns true', function (): void {
    $message = Message::withoutTenantScope()->create([
        'tenant_id' => $this->tenant->id,
        'conversation_id' => $this->conversation->id,
        'direction' => 'outbound',
        'type' => 'text',
        'body' => 'test',
        'status' => 'sent',
        'metadata' => ['origin' => MessageOrigin::Automation->value],
    ]);

    expect($this->classifier->isAutomation($message))->toBeTrue();
})->group('F29-U2-ORIGIN');

it('F29-U2-ORIGIN-02: human origin with active member sent_by_user_id returns false', function (): void {
    $user = User::factory()->create();
    make_tenant_member($user, $this->tenant, 'agent');

    $message = Message::withoutTenantScope()->create([
        'tenant_id' => $this->tenant->id,
        'conversation_id' => $this->conversation->id,
        'direction' => 'outbound',
        'type' => 'text',
        'body' => 'test',
        'status' => 'sent',
        'metadata' => ['origin' => MessageOrigin::Human->value],
    ]);
    $message->forceFill(['sent_by_user_id' => $user->id])->save();

    expect($this->classifier->isAutomation($message))->toBeFalse();
})->group('F29-U2-ORIGIN');

it('F29-U2-ORIGIN-03: human origin with null sent_by_user_id returns true (automation masquerading)', function (): void {
    $message = Message::withoutTenantScope()->create([
        'tenant_id' => $this->tenant->id,
        'conversation_id' => $this->conversation->id,
        'direction' => 'outbound',
        'type' => 'text',
        'body' => 'test',
        'status' => 'sent',
        'sent_by_user_id' => null,
        'metadata' => ['origin' => MessageOrigin::Human->value],
    ]);

    expect($this->classifier->isAutomation($message))->toBeTrue();
})->group('F29-U2-ORIGIN');

it('F29-U2-ORIGIN-04: human origin with inactive membership returns true', function (): void {
    $user = User::factory()->create();
    make_tenant_member($user, $this->tenant, 'agent');
    DB::table('tenant_users')
        ->where('tenant_id', $this->tenant->id)
        ->where('user_id', $user->id)
        ->update(['status' => TenantMembershipStatus::Disabled->value]);

    $message = Message::withoutTenantScope()->create([
        'tenant_id' => $this->tenant->id,
        'conversation_id' => $this->conversation->id,
        'direction' => 'outbound',
        'type' => 'text',
        'body' => 'test',
        'status' => 'sent',
        'metadata' => ['origin' => MessageOrigin::Human->value],
    ]);
    $message->forceFill(['sent_by_user_id' => $user->id])->save();

    expect($this->classifier->isAutomation($message))->toBeTrue();
})->group('F29-U2-ORIGIN');

it('F29-U2-ORIGIN-05: handoff origin with sent_by_user_id returns true', function (): void {
    $user = User::factory()->create();
    make_tenant_member($user, $this->tenant, 'agent');

    $message = Message::withoutTenantScope()->create([
        'tenant_id' => $this->tenant->id,
        'conversation_id' => $this->conversation->id,
        'direction' => 'outbound',
        'type' => 'text',
        'body' => 'test',
        'status' => 'sent',
        'metadata' => ['origin' => MessageOrigin::Handoff->value],
    ]);
    $message->forceFill(['sent_by_user_id' => $user->id])->save();

    expect($this->classifier->isAutomation($message))->toBeTrue();
})->group('F29-U2-ORIGIN');

it('F29-U2-ORIGIN-06: handoff origin with no sent_by_user_id and no audit log returns true', function (): void {
    $message = Message::withoutTenantScope()->create([
        'tenant_id' => $this->tenant->id,
        'conversation_id' => $this->conversation->id,
        'direction' => 'outbound',
        'type' => 'text',
        'body' => 'test',
        'status' => 'sent',
        'sent_by_user_id' => null,
        'metadata' => ['origin' => MessageOrigin::Handoff->value, 'flow_execution_id' => 'exec-123'],
    ]);

    expect($this->classifier->isAutomation($message))->toBeTrue();
})->group('F29-U2-ORIGIN');

it('F29-U2-ORIGIN-07: handoff origin with matching audit log returns false (real handoff)', function (): void {
    AuditLog::query()->create([
        'tenant_id' => $this->tenant->id,
        'action' => 'flow.handoff',
        'subject_type' => Conversation::class,
        'subject_id' => $this->conversation->id,
        'data' => ['flow_execution_id' => 'exec-456'],
    ]);

    $message = Message::withoutTenantScope()->create([
        'tenant_id' => $this->tenant->id,
        'conversation_id' => $this->conversation->id,
        'direction' => 'outbound',
        'type' => 'text',
        'body' => 'test',
        'status' => 'sent',
        'sent_by_user_id' => null,
        'metadata' => ['origin' => MessageOrigin::Handoff->value, 'flow_execution_id' => 'exec-456'],
    ]);

    expect($this->classifier->isAutomation($message))->toBeFalse();
})->group('F29-U2-ORIGIN');

it('F29-U2-ORIGIN-08: handoff with empty flow_execution_id returns true', function (): void {
    $message = Message::withoutTenantScope()->create([
        'tenant_id' => $this->tenant->id,
        'conversation_id' => $this->conversation->id,
        'direction' => 'outbound',
        'type' => 'text',
        'body' => 'test',
        'status' => 'sent',
        'sent_by_user_id' => null,
        'metadata' => ['origin' => MessageOrigin::Handoff->value, 'flow_execution_id' => ''],
    ]);

    expect($this->classifier->isAutomation($message))->toBeTrue();
})->group('F29-U2-ORIGIN');

it('F29-U2-ORIGIN-09: unknown origin returns true', function (): void {
    $message = Message::withoutTenantScope()->create([
        'tenant_id' => $this->tenant->id,
        'conversation_id' => $this->conversation->id,
        'direction' => 'outbound',
        'type' => 'text',
        'body' => 'test',
        'status' => 'sent',
        'metadata' => ['origin' => 'unknown_value'],
    ]);

    expect($this->classifier->isAutomation($message))->toBeTrue();
})->group('F29-U2-ORIGIN');
