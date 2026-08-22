<?php

declare(strict_types=1);

use App\Application\Notifications\Services\NotificationService;
use App\Domain\Conversations\Enums\ConversationStatus;
use App\Domain\Conversations\Models\Conversation;
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
| Multi-Tenancy Notification Tests (FASE 22 U2)
|--------------------------------------------------------------------------
|
| NOTIF-MT-U2-01..06 — Tenant isolation for notification dispatch.
| Corren en SQLite :memory:.
|
*/

beforeEach(function (): void {
    $this->tenantA = Tenant::factory()->create();
    $this->tenantB = Tenant::factory()->create();

    TenantContext::setId($this->tenantA->id);

    $this->userA = User::factory()->create();
    make_tenant_member($this->userA, $this->tenantA, 'owner');

    $this->userB = User::factory()->create();
    make_tenant_member($this->userB, $this->tenantB, 'owner');

    $this->agentA = User::factory()->create();
    make_tenant_member($this->agentA, $this->tenantA, 'agent');

    $this->contactA = make_contact($this->tenantA);

    TenantContext::setId($this->tenantA->id);
    $this->conversationA = Conversation::query()->create([
        'tenant_id' => $this->tenantA->id,
        'contact_id' => $this->contactA->id,
        'status' => ConversationStatus::Open,
    ]);

    $this->contactB = make_contact($this->tenantB);

    TenantContext::setId($this->tenantB->id);
    $this->conversationB = Conversation::query()->create([
        'tenant_id' => $this->tenantB->id,
        'contact_id' => $this->contactB->id,
        'status' => ConversationStatus::Open,
    ]);

    $this->service = app(NotificationService::class);
});

it('NOTIF-MT-U2-01: tenant A notification targets are allowed within A', function (): void {
    TenantContext::setId($this->tenantA->id);
    $notifications = $this->service->handleHandoffRequested($this->tenantA, $this->conversationA);

    $this->assertNotEmpty($notifications);

    foreach ($notifications as $notification) {
        $this->assertEquals($this->tenantA->id, $notification->tenant_id);
    }
})->group('NOTIF-MT-U2-01');

it('NOTIF-MT-U2-02: tenant A cannot create notification for tenant B user', function (): void {
    $result = $this->service->handleConversationAssigned(
        $this->tenantA,
        $this->conversationA,
        $this->userB->id,
    );

    $this->assertNull($result);
})->group('NOTIF-MT-U2-02');

it('NOTIF-MT-U2-03: tenant-wide A not visible as B', function (): void {
    TenantContext::setId($this->tenantA->id);
    $this->service->handleHandoffRequested($this->tenantA, $this->conversationA);

    $notificationsInB = Notification::where('tenant_id', $this->tenantB->id)->count();
    $this->assertEquals(0, $notificationsInB);

    $notificationsInA = Notification::where('tenant_id', $this->tenantA->id)->count();
    $this->assertGreaterThan(0, $notificationsInA);
})->group('NOTIF-MT-U2-03');

it('NOTIF-MT-U2-04: inactive target in tenant A skipped', function (): void {
    $inactive = User::factory()->create();
    make_tenant_member($inactive, $this->tenantA, 'agent');

    TenantUser::query()
        ->where('tenant_id', $this->tenantA->id)
        ->where('user_id', $inactive->id)
        ->update(['status' => 'disabled']);

    TenantContext::setId($this->tenantA->id);
    $result = $this->service->handleConversationAssigned(
        $this->tenantA,
        $this->conversationA,
        $inactive->id,
    );

    $this->assertNull($result);
    $this->assertDatabaseCount('notifications', 0);
})->group('NOTIF-MT-U2-04');

it('NOTIF-MT-U2-05: event tenant A cannot create B row', function (): void {
    TenantContext::setId($this->tenantA->id);
    $this->service->handleHandoffRequested($this->tenantA, $this->conversationA);

    $countB = Notification::where('tenant_id', $this->tenantB->id)->count();
    $this->assertEquals(0, $countB);
})->group('NOTIF-MT-U2-05');

it('NOTIF-MT-U2-06: sequential A then B context safe', function (): void {
    TenantContext::setId($this->tenantA->id);
    $this->service->handleHandoffRequested($this->tenantA, $this->conversationA);

    TenantContext::clear();
    TenantContext::setId($this->tenantB->id);
    $this->service->handleHandoffRequested($this->tenantB, $this->conversationB);

    TenantContext::setId($this->tenantA->id);
    $countA = Notification::where('tenant_id', $this->tenantA->id)->count();

    TenantContext::setId($this->tenantB->id);
    $countB = Notification::where('tenant_id', $this->tenantB->id)->count();

    $this->assertGreaterThan(0, $countA);
    $this->assertGreaterThan(0, $countB);

    TenantContext::setId($this->tenantA->id);
    $tenantAUserIds = Notification::where('tenant_id', $this->tenantA->id)->pluck('user_id')->toArray();

    TenantContext::setId($this->tenantB->id);
    $tenantBUserIds = Notification::where('tenant_id', $this->tenantB->id)->pluck('user_id')->toArray();

    $this->assertContains($this->userA->id, $tenantAUserIds);
    $this->assertNotContains($this->userB->id, $tenantAUserIds);
    $this->assertContains($this->userB->id, $tenantBUserIds);
    $this->assertNotContains($this->userA->id, $tenantBUserIds);
})->group('NOTIF-MT-U2-06');
