<?php

declare(strict_types=1);

use App\Application\Notifications\Services\NotificationService;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Conversations\Enums\ConversationStatus;
use App\Domain\Conversations\Models\Conversation;
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
| NotificationService Tests (FASE 22 U2)
|--------------------------------------------------------------------------
|
| NOTIF-SVC-01..10 — Unit tests for the NotificationService.
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

    $this->agent = User::factory()->create();
    make_tenant_member($this->agent, $this->tenant, 'agent');

    $this->contact = make_contact($this->tenant);

    TenantContext::setId($this->tenant->id);
    $this->conversation = Conversation::query()->create([
        'tenant_id' => $this->tenant->id,
        'contact_id' => $this->contact->id,
        'status' => ConversationStatus::Open,
    ]);

    $this->service = app(NotificationService::class);
});

it('NOTIF-SVC-01: handleHandoffRequested creates notification per active member', function (): void {
    $notifications = $this->service->handleHandoffRequested($this->tenant, $this->conversation);

    $this->assertCount(3, $notifications);

    $userIds = array_map(fn ($n) => $n->user_id, $notifications);
    $this->assertContains($this->owner->id, $userIds);
    $this->assertContains($this->admin->id, $userIds);
    $this->assertContains($this->agent->id, $userIds);
})->group('NOTIF-SVC-01');

it('NOTIF-SVC-02: tenant validation — notifications are tenant-scoped', function (): void {
    $notifications = $this->service->handleHandoffRequested($this->tenant, $this->conversation);

    foreach ($notifications as $notification) {
        $this->assertEquals($this->tenant->id, $notification->tenant_id);
    }

    $this->assertDatabaseCount('notifications', 3);

    $countOther = Notification::where('tenant_id', $this->otherTenant->id)->count();
    $this->assertEquals(0, $countOther);
})->group('NOTIF-SVC-02');

it('NOTIF-SVC-03: inactive membership excluded from handoff fan-out', function (): void {
    $inactive = User::factory()->create();
    make_tenant_member($inactive, $this->tenant, 'agent');

    TenantUser::query()
        ->where('tenant_id', $this->tenant->id)
        ->where('user_id', $inactive->id)
        ->update(['status' => 'disabled']);

    $notifications = $this->service->handleHandoffRequested($this->tenant, $this->conversation);

    $this->assertCount(3, $notifications);

    $userIds = array_map(fn ($n) => $n->user_id, $notifications);
    $this->assertNotContains($inactive->id, $userIds);
})->group('NOTIF-SVC-03');

it('NOTIF-SVC-04: correct NotificationType for handoff', function (): void {
    $notifications = $this->service->handleHandoffRequested($this->tenant, $this->conversation);

    foreach ($notifications as $notification) {
        $this->assertEquals(NotificationType::HandoffRequested, $notification->type);
    }
})->group('NOTIF-SVC-04');

it('NOTIF-SVC-05: correct priority for handoff vs assigned', function (): void {
    $handoffNotifications = $this->service->handleHandoffRequested($this->tenant, $this->conversation);
    foreach ($handoffNotifications as $n) {
        $this->assertEquals(NotificationPriority::High, $n->priority);
    }

    $assignedNotification = $this->service->handleConversationAssigned(
        $this->tenant,
        $this->conversation,
        $this->agent->id,
    );
    $this->assertEquals(NotificationPriority::Normal, $assignedNotification->priority);
})->group('NOTIF-SVC-05');

it('NOTIF-SVC-06: safe metadata in data JSON', function (): void {
    $notifications = $this->service->handleHandoffRequested($this->tenant, $this->conversation);

    foreach ($notifications as $notification) {
        $this->assertArrayHasKey('conversation_id', $notification->data);
        $this->assertArrayHasKey('event', $notification->data);
        $this->assertEquals($this->conversation->id, $notification->data['conversation_id']);
        $this->assertEquals('handoff_requested', $notification->data['event']);

        $this->assertArrayNotHasKey('phone', $notification->data);
        $this->assertArrayNotHasKey('email', $notification->data);
        $this->assertArrayNotHasKey('message_body', $notification->data);
        $this->assertArrayNotHasKey('contact_name', $notification->data);
    }
})->group('NOTIF-SVC-06');

it('NOTIF-SVC-07: audit record created with safe payload', function (): void {
    $this->service->handleHandoffRequested($this->tenant, $this->conversation);

    $this->assertDatabaseHas('audit_logs', [
        'tenant_id' => $this->tenant->id,
        'action' => 'notification.created',
    ]);

    $audit = AuditLog::query()
        ->where('tenant_id', $this->tenant->id)
        ->where('action', 'notification.created')
        ->first();

    $this->assertNotNull($audit);
    $this->assertArrayHasKey('notification_id', $audit->data);
    $this->assertArrayHasKey('type', $audit->data);
    $this->assertArrayHasKey('priority', $audit->data);
    $this->assertArrayHasKey('target_user_id', $audit->data);
    $this->assertArrayNotHasKey('title', $audit->data);
    $this->assertArrayNotHasKey('body', $audit->data);
})->group('NOTIF-SVC-07');

it('NOTIF-SVC-08: repeated handoff creates independent notifications', function (): void {
    $this->service->handleHandoffRequested($this->tenant, $this->conversation);
    $this->service->handleHandoffRequested($this->tenant, $this->conversation);

    $count = Notification::where('tenant_id', $this->tenant->id)
        ->where('type', NotificationType::HandoffRequested)
        ->count();

    $this->assertEquals(6, $count);
})->group('NOTIF-SVC-08');

it('NOTIF-SVC-09: handleConversationAssigned creates notification for specific agent', function (): void {
    $notification = $this->service->handleConversationAssigned(
        $this->tenant,
        $this->conversation,
        $this->agent->id,
    );

    $this->assertNotNull($notification);
    $this->assertEquals($this->agent->id, $notification->user_id);
    $this->assertEquals(NotificationType::ConversationAssigned, $notification->type);
    $this->assertEquals(NotificationPriority::Normal, $notification->priority);
    $this->assertEquals($this->conversation->id, $notification->data['conversation_id']);
})->group('NOTIF-SVC-09');

it('NOTIF-SVC-10: handleConversationAssigned returns null for inactive member', function (): void {
    $inactive = User::factory()->create();
    make_tenant_member($inactive, $this->tenant, 'agent');

    TenantUser::query()
        ->where('tenant_id', $this->tenant->id)
        ->where('user_id', $inactive->id)
        ->update(['status' => 'disabled']);

    $result = $this->service->handleConversationAssigned(
        $this->tenant,
        $this->conversation,
        $inactive->id,
    );

    $this->assertNull($result);
    $this->assertDatabaseCount('notifications', 0);
})->group('NOTIF-SVC-10');
