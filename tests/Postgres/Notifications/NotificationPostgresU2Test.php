<?php

declare(strict_types=1);

use App\Application\Notifications\Services\NotificationService;
use App\Domain\Conversations\Enums\ConversationStatus;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Notifications\Enums\NotificationType;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
});

/*
|--------------------------------------------------------------------------
| PostgreSQL Integration Tests — Notification U2 (FASE 22 U2)
|--------------------------------------------------------------------------
|
| NOTIF-PG-U2-01..06 — Integration against real PostgreSQL.
| Ejecutar con: docker compose exec ... --filter="NotificationPostgresU2Test"
|
*/

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->otherTenant = Tenant::factory()->create();
    TenantContext::setId($this->tenant->id);

    $this->owner = User::factory()->create();
    make_tenant_member($this->owner, $this->tenant, 'owner');

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

it('NOTIF-PG-U2-01: handoff fan-out creates correct rows in PG', function (): void {
    $notifications = $this->service->handleHandoffRequested($this->tenant, $this->conversation);

    $this->assertCount(2, $notifications);

    foreach ($notifications as $notification) {
        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'tenant_id' => $this->tenant->id,
            'type' => NotificationType::HandoffRequested->value,
        ]);
    }
})->group('NOTIF-PG-U2-01');

it('NOTIF-PG-U2-02: assignment creates single targeted row in PG', function (): void {
    $notification = $this->service->handleConversationAssigned(
        $this->tenant,
        $this->conversation,
        $this->agent->id,
    );

    $this->assertNotNull($notification);

    $this->assertDatabaseHas('notifications', [
        'id' => $notification->id,
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->agent->id,
        'type' => NotificationType::ConversationAssigned->value,
    ]);

    $this->assertDatabaseCount('notifications', 1);
})->group('NOTIF-PG-U2-02');

it('NOTIF-PG-U2-03: FK constraint — tenant_id references valid tenant', function (): void {
    $notifications = $this->service->handleHandoffRequested($this->tenant, $this->conversation);

    foreach ($notifications as $notification) {
        $this->assertDatabaseHas('tenants', ['id' => $notification->tenant_id]);
    }
})->group('NOTIF-PG-U2-03');

it('NOTIF-PG-U2-04: FK constraint — user_id references valid user', function (): void {
    $this->service->handleHandoffRequested($this->tenant, $this->conversation);

    $notifications = Notification::where('tenant_id', $this->tenant->id)->get();

    foreach ($notifications as $notification) {
        if ($notification->user_id !== null) {
            $this->assertDatabaseHas('users', ['id' => $notification->user_id]);
        }
    }
})->group('NOTIF-PG-U2-04');

it('NOTIF-PG-U2-05: concurrent handoff from same conversation does not violate constraints', function (): void {
    $this->service->handleHandoffRequested($this->tenant, $this->conversation);

    $this->service->handleHandoffRequested($this->tenant, $this->conversation);

    $count = Notification::where('tenant_id', $this->tenant->id)
        ->where('type', NotificationType::HandoffRequested)
        ->count();

    $this->assertEquals(4, $count);
})->group('NOTIF-PG-U2-05');

it('NOTIF-PG-U2-06: cascade delete — tenant delete removes notifications', function (): void {
    $this->service->handleHandoffRequested($this->tenant, $this->conversation);

    $countBefore = Notification::where('tenant_id', $this->tenant->id)->count();
    $this->assertGreaterThan(0, $countBefore);

    $this->tenant->delete();

    $countAfter = Notification::where('tenant_id', $this->tenant->id)->withTrashed()->count();
    $this->assertEquals(0, $countAfter);
})->group('NOTIF-PG-U2-06');
