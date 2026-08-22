<?php

declare(strict_types=1);

use App\Application\Conversations\Services\ConversationService;
use App\Domain\Conversations\Enums\ConversationStatus;
use App\Domain\Conversations\Models\Conversation;
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
| Assignment Notification Integration Tests (FASE 22 U2)
|--------------------------------------------------------------------------
|
| NOTIF-ASG-01..10 — Integration: ConversationService → listener → notifications.
| Corren en SQLite :memory:.
|
*/

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->otherTenant = Tenant::factory()->create();
    TenantContext::setId($this->tenant->id);

    $this->owner = User::factory()->create();
    make_tenant_member($this->owner, $this->tenant, 'owner');

    $this->admin = User::factory()->create();
    make_tenant_member($this->admin, $this->tenant, 'admin');

    $this->agentA = User::factory()->create();
    make_tenant_member($this->agentA, $this->tenant, 'agent');

    $this->agentB = User::factory()->create();
    make_tenant_member($this->agentB, $this->tenant, 'agent');

    $this->contact = make_contact($this->tenant);

    TenantContext::setId($this->tenant->id);
    $this->conversation = Conversation::query()->create([
        'tenant_id' => $this->tenant->id,
        'contact_id' => $this->contact->id,
        'status' => ConversationStatus::Open,
    ]);

    $this->conversationService = app(ConversationService::class);
});

it('NOTIF-ASG-01: assign creates notification for the assigned agent', function (): void {
    $this->conversationService->assign($this->owner, $this->tenant, $this->conversation->id, $this->agentA->id);

    $this->assertDatabaseHas('notifications', [
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->agentA->id,
        'type' => NotificationType::ConversationAssigned->value,
    ]);
})->group('NOTIF-ASG-01');

it('NOTIF-ASG-02: other agents do not receive assignment notification', function (): void {
    $this->conversationService->assign($this->owner, $this->tenant, $this->conversation->id, $this->agentA->id);

    $count = Notification::where('tenant_id', $this->tenant->id)
        ->where('type', NotificationType::ConversationAssigned)
        ->where('user_id', $this->agentB->id)
        ->count();

    $this->assertEquals(0, $count);
})->group('NOTIF-ASG-02');

it('NOTIF-ASG-03: owner not auto-targeted unless assigned', function (): void {
    $this->conversationService->assign($this->owner, $this->tenant, $this->conversation->id, $this->agentA->id);

    $count = Notification::where('tenant_id', $this->tenant->id)
        ->where('type', NotificationType::ConversationAssigned)
        ->where('user_id', $this->owner->id)
        ->count();

    $this->assertEquals(0, $count);
})->group('NOTIF-ASG-03');

it('NOTIF-ASG-04: same assignment no-op — no new notification', function (): void {
    $this->conversationService->assign($this->owner, $this->tenant, $this->conversation->id, $this->agentA->id);

    $countAfterFirst = Notification::where('tenant_id', $this->tenant->id)
        ->where('type', NotificationType::ConversationAssigned)
        ->count();

    $this->conversationService->assign($this->owner, $this->tenant, $this->conversation->id, $this->agentA->id);

    $countAfterSecond = Notification::where('tenant_id', $this->tenant->id)
        ->where('type', NotificationType::ConversationAssigned)
        ->count();

    $this->assertEquals($countAfterFirst, $countAfterSecond);
})->group('NOTIF-ASG-04');

it('NOTIF-ASG-05: transfer creates notification for new agent', function (): void {
    $this->conversationService->assign($this->owner, $this->tenant, $this->conversation->id, $this->agentA->id);

    Notification::query()->where('type', NotificationType::ConversationAssigned)->delete();

    $this->conversationService->transfer($this->owner, $this->tenant, $this->conversation->id, $this->agentB->id);

    $this->assertDatabaseHas('notifications', [
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->agentB->id,
        'type' => NotificationType::ConversationAssigned->value,
    ]);
})->group('NOTIF-ASG-05');

it('NOTIF-ASG-06: previous agent does not receive notification on transfer', function (): void {
    $this->conversationService->assign($this->owner, $this->tenant, $this->conversation->id, $this->agentA->id);

    Notification::query()->where('type', NotificationType::ConversationAssigned)->delete();

    $this->conversationService->transfer($this->owner, $this->tenant, $this->conversation->id, $this->agentB->id);

    $count = Notification::where('tenant_id', $this->tenant->id)
        ->where('type', NotificationType::ConversationAssigned)
        ->where('user_id', $this->agentA->id)
        ->count();

    $this->assertEquals(0, $count);
})->group('NOTIF-ASG-06');

it('NOTIF-ASG-07: inactive target blocked — no notification created', function (): void {
    $inactive = User::factory()->create();
    make_tenant_member($inactive, $this->tenant, 'agent');

    TenantUser::query()
        ->where('tenant_id', $this->tenant->id)
        ->where('user_id', $inactive->id)
        ->update(['status' => 'disabled']);

    try {
        $this->conversationService->assign($this->owner, $this->tenant, $this->conversation->id, $inactive->id);
    } catch (Throwable) {
        // Assignment may throw ConversationAgentNotInTenantException — expected
    }

    $this->assertDatabaseCount('notifications', 0);
})->group('NOTIF-ASG-07');

it('NOTIF-ASG-08: cross-tenant user not notified', function (): void {
    $otherTenantUser = User::factory()->create();
    make_tenant_member($otherTenantUser, $this->otherTenant, 'owner');

    try {
        $this->conversationService->assign($this->owner, $this->tenant, $this->conversation->id, $otherTenantUser->id);
    } catch (Throwable) {
        // Expected: user is not a member of this tenant
    }

    $this->assertDatabaseCount('notifications', 0);
})->group('NOTIF-ASG-08');

it('NOTIF-ASG-09: assignment notification has safe payload', function (): void {
    $this->conversationService->assign($this->owner, $this->tenant, $this->conversation->id, $this->agentA->id);

    $notification = Notification::where('tenant_id', $this->tenant->id)
        ->where('user_id', $this->agentA->id)
        ->where('type', NotificationType::ConversationAssigned)
        ->first();

    $this->assertNotNull($notification);
    $this->assertEquals($this->conversation->id, $notification->data['conversation_id']);
    $this->assertArrayNotHasKey('phone', $notification->data);
    $this->assertArrayNotHasKey('email', $notification->data);
    $this->assertArrayNotHasKey('contact_name', $notification->data);
    $this->assertArrayNotHasKey('message_body', $notification->data);
})->group('NOTIF-ASG-09');

it('NOTIF-ASG-10: afterCommit behavior — notification persists after transaction', function (): void {
    $this->conversationService->assign($this->owner, $this->tenant, $this->conversation->id, $this->agentA->id);

    $notification = Notification::where('tenant_id', $this->tenant->id)
        ->where('user_id', $this->agentA->id)
        ->where('type', NotificationType::ConversationAssigned)
        ->first();

    $this->assertNotNull($notification);
    $this->assertNull($notification->read_at);
    $this->assertNotNull($notification->created_at);
})->group('NOTIF-ASG-10');
