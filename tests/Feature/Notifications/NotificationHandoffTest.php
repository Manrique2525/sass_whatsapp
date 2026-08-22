<?php

declare(strict_types=1);

use App\Application\Conversations\Services\HumanHandoffService;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Conversations\Enums\ConversationStatus;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Flows\Enums\FlowExecutionStatus;
use App\Domain\Flows\Enums\FlowNodeType;
use App\Domain\Flows\Models\FlowExecution;
use App\Domain\Flows\Models\FlowNode;
use App\Domain\Notifications\Enums\NotificationPriority;
use App\Domain\Notifications\Enums\NotificationType;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\TenantUser;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
});

/*
|--------------------------------------------------------------------------
| Handoff Notification Integration Tests (FASE 22 U2)
|--------------------------------------------------------------------------
|
| NOTIF-HO-01..10 — Integration: HumanHandoffService → listener → notifications.
| Corren en SQLite :memory:.
|
*/

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    TenantContext::setId($this->tenant->id);

    $this->owner = User::factory()->create();
    make_tenant_member($this->owner, $this->tenant, 'owner');

    $this->admin = User::factory()->create();
    make_tenant_member($this->admin, $this->tenant, 'admin');

    $this->agent = User::factory()->create();
    make_tenant_member($this->agent, $this->tenant, 'agent');

    $this->otherTenant = Tenant::factory()->create();
    $this->contact = make_contact($this->tenant);

    TenantContext::setId($this->tenant->id);
    $this->conversation = Conversation::query()->create([
        'tenant_id' => $this->tenant->id,
        'contact_id' => $this->contact->id,
        'status' => ConversationStatus::Open,
    ]);

    $this->chatbot = make_chatbot($this->tenant);
    $this->flow = make_flow($this->tenant, $this->chatbot, ['status' => 'published']);

    TenantContext::setId($this->tenant->id);
    $this->humanNode = FlowNode::query()->create([
        'flow_id' => $this->flow->id,
        'tenant_id' => $this->tenant->id,
        'type' => FlowNodeType::Human->value,
        'name' => 'Human Node',
        'config' => [],
        'is_start' => true,
        'position_x' => 0,
        'position_y' => 0,
    ]);

    $this->execution = FlowExecution::query()->create([
        'flow_id' => $this->flow->id,
        'conversation_id' => $this->conversation->id,
        'tenant_id' => $this->tenant->id,
        'status' => FlowExecutionStatus::Waiting,
        'current_node_id' => $this->humanNode->id,
        'context' => [],
    ]);

    $this->handoffService = app(HumanHandoffService::class);
});

it('NOTIF-HO-01: handoff creates notifications for all active members', function (): void {
    $this->handoffService->handoff(
        $this->tenant,
        $this->conversation,
        $this->execution,
        null,
    );

    $count = Notification::where('tenant_id', $this->tenant->id)
        ->where('type', NotificationType::HandoffRequested)
        ->count();

    $this->assertEquals(3, $count);
})->group('NOTIF-HO-01');

it('NOTIF-HO-02: owner receives handoff notification', function (): void {
    $this->handoffService->handoff(
        $this->tenant,
        $this->conversation,
        $this->execution,
        null,
    );

    $this->assertDatabaseHas('notifications', [
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->owner->id,
        'type' => NotificationType::HandoffRequested->value,
    ]);
})->group('NOTIF-HO-02');

it('NOTIF-HO-03: admin receives handoff notification', function (): void {
    $this->handoffService->handoff(
        $this->tenant,
        $this->conversation,
        $this->execution,
        null,
    );

    $this->assertDatabaseHas('notifications', [
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->admin->id,
        'type' => NotificationType::HandoffRequested->value,
    ]);
})->group('NOTIF-HO-03');

it('NOTIF-HO-04: agent receives handoff notification', function (): void {
    $this->handoffService->handoff(
        $this->tenant,
        $this->conversation,
        $this->execution,
        null,
    );

    $this->assertDatabaseHas('notifications', [
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->agent->id,
        'type' => NotificationType::HandoffRequested->value,
    ]);
})->group('NOTIF-HO-04');

it('NOTIF-HO-05: inactive member excluded from handoff notifications', function (): void {
    $inactive = User::factory()->create();
    make_tenant_member($inactive, $this->tenant, 'agent');

    TenantUser::query()
        ->where('tenant_id', $this->tenant->id)
        ->where('user_id', $inactive->id)
        ->update(['status' => 'disabled']);

    $this->handoffService->handoff(
        $this->tenant,
        $this->conversation,
        $this->execution,
        null,
    );

    $this->assertDatabaseMissing('notifications', [
        'tenant_id' => $this->tenant->id,
        'user_id' => $inactive->id,
        'type' => NotificationType::HandoffRequested->value,
    ]);
})->group('NOTIF-HO-05');

it('NOTIF-HO-06: other tenant members not notified', function (): void {
    $otherUser = User::factory()->create();
    make_tenant_member($otherUser, $this->otherTenant, 'owner');

    $this->handoffService->handoff(
        $this->tenant,
        $this->conversation,
        $this->execution,
        null,
    );

    $this->assertDatabaseMissing('notifications', [
        'user_id' => $otherUser->id,
        'type' => NotificationType::HandoffRequested->value,
    ]);
})->group('NOTIF-HO-06');

it('NOTIF-HO-07: handoff notification title and body are safe generic text', function (): void {
    $this->handoffService->handoff(
        $this->tenant,
        $this->conversation,
        $this->execution,
        null,
    );

    $notifications = Notification::where('tenant_id', $this->tenant->id)
        ->where('type', NotificationType::HandoffRequested)
        ->get();

    foreach ($notifications as $notification) {
        $this->assertNotEmpty($notification->title);
        $this->assertNotEmpty($notification->body);
        $this->assertStringNotContainsString('<', $notification->title);
        $this->assertStringNotContainsString('<', $notification->body);
        $this->assertStringNotContainsString($this->contact->phone ?? 'no-phone', $notification->body);
        $this->assertStringNotContainsString($this->contact->name, $notification->body);
    }
})->group('NOTIF-HO-07');

it('NOTIF-HO-08: handoff notification priority is high', function (): void {
    $this->handoffService->handoff(
        $this->tenant,
        $this->conversation,
        $this->execution,
        null,
    );

    $notifications = Notification::where('tenant_id', $this->tenant->id)
        ->where('type', NotificationType::HandoffRequested)
        ->get();

    foreach ($notifications as $notification) {
        $this->assertEquals(NotificationPriority::High, $notification->priority);
    }
})->group('NOTIF-HO-08');

it('NOTIF-HO-09: handoff notification data contains safe conversation_id', function (): void {
    $this->handoffService->handoff(
        $this->tenant,
        $this->conversation,
        $this->execution,
        null,
    );

    $notifications = Notification::where('tenant_id', $this->tenant->id)
        ->where('type', NotificationType::HandoffRequested)
        ->get();

    foreach ($notifications as $notification) {
        $this->assertEquals($this->conversation->id, $notification->data['conversation_id']);
        $this->assertArrayNotHasKey('phone', $notification->data);
        $this->assertArrayNotHasKey('email', $notification->data);
        $this->assertArrayNotHasKey('contact_name', $notification->data);
        $this->assertArrayNotHasKey('message_body', $notification->data);
    }
})->group('NOTIF-HO-09');

it('NOTIF-HO-10: duplicate handoff idempotency — no duplicate handoff operations', function (): void {
    $this->handoffService->handoff(
        $this->tenant,
        $this->conversation,
        $this->execution,
        null,
    );

    $countAfterFirst = Notification::where('tenant_id', $this->tenant->id)
        ->where('type', NotificationType::HandoffRequested)
        ->count();

    $auditCountAfterFirst = AuditLog::where('tenant_id', $this->tenant->id)
        ->where('action', 'flow.handoff')
        ->count();

    $this->handoffService->handoff(
        $this->tenant,
        $this->conversation,
        $this->execution,
        null,
    );

    $auditCountAfterSecond = AuditLog::where('tenant_id', $this->tenant->id)
        ->where('action', 'flow.handoff')
        ->count();

    $this->assertEquals($auditCountAfterFirst, $auditCountAfterSecond);

    $this->conversation->refresh();
    $this->assertNotNull($this->conversation->handoff_requested_at);
})->group('NOTIF-HO-10');
