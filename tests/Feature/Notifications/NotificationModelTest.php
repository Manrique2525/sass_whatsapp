<?php

declare(strict_types=1);

use App\Domain\Notifications\Enums\NotificationPriority;
use App\Domain\Notifications\Enums\NotificationType;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
});

/*
|--------------------------------------------------------------------------
| Notification Model Tests (FASE 22 U1)
|--------------------------------------------------------------------------
|
| NOTIF-DB-01..15 — Domain invariants for notifications table.
| Corren en SQLite :memory:.
|
*/

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    TenantContext::setId($this->tenant->id);

    $this->user = User::factory()->create();
    $this->tenant->users()->attach($this->user, ['role' => 'admin', 'status' => 'active']);
});

it('NOTIF-DB-01: Notification can be created via factory', function (): void {
    $notification = Notification::factory()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->user->id,
    ]);

    $this->assertNotNull($notification->id);
    $this->assertEquals($this->tenant->id, $notification->tenant_id);
    $this->assertEquals($this->user->id, $notification->user_id);
})->group('NOTIF-DB-01');

it('NOTIF-DB-02: Notification uses UUID primary key', function (): void {
    $notification = Notification::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $this->assertMatchesRegularExpression(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
        $notification->id,
    );
    $this->assertEquals(36, strlen($notification->id));
})->group('NOTIF-DB-02');

it('NOTIF-DB-03: tenant_id is auto-assigned from TenantContext', function (): void {
    $notification = Notification::factory()->create([
        'user_id' => $this->user->id,
    ]);

    $this->assertEquals($this->tenant->id, $notification->tenant_id);
})->group('NOTIF-DB-03');

it('NOTIF-DB-04: tenant_id is NOT mass assignable', function (): void {
    $notification = Notification::factory()->create([
        'user_id' => $this->user->id,
    ]);

    $notification->fill(['tenant_id' => 'fake-tenant-id']);
    $this->assertEquals($this->tenant->id, $notification->tenant_id);
})->group('NOTIF-DB-04');

it('NOTIF-DB-05: type is cast to NotificationType enum', function (): void {
    $notification = Notification::factory()->create([
        'tenant_id' => $this->tenant->id,
        'type' => NotificationType::HandoffRequested,
    ]);

    $this->assertInstanceOf(NotificationType::class, $notification->type);
    $this->assertEquals(NotificationType::HandoffRequested, $notification->type);
})->group('NOTIF-DB-05');

it('NOTIF-DB-06: priority is cast to NotificationPriority enum', function (): void {
    $notification = Notification::factory()->create([
        'tenant_id' => $this->tenant->id,
        'priority' => NotificationPriority::High,
    ]);

    $this->assertInstanceOf(NotificationPriority::class, $notification->priority);
    $this->assertEquals(NotificationPriority::High, $notification->priority);
})->group('NOTIF-DB-06');

it('NOTIF-DB-07: data is cast to array', function (): void {
    $payload = ['conversation_id' => (string) Str::uuid(), 'event' => 'handoff'];
    $notification = Notification::factory()->create([
        'tenant_id' => $this->tenant->id,
        'data' => $payload,
    ]);

    $this->assertIsArray($notification->data);
    $this->assertEquals($payload['conversation_id'], $notification->data['conversation_id']);
    $this->assertEquals('handoff', $notification->data['event']);
})->group('NOTIF-DB-07');

it('NOTIF-DB-08: read_at null means unread', function (): void {
    $notification = Notification::factory()->unread()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $this->assertNull($notification->read_at);
    $this->assertFalse($notification->isRead());
})->group('NOTIF-DB-08');

it('NOTIF-DB-09: read_at is cast to datetime', function (): void {
    $now = now()->subMinute();
    $notification = Notification::factory()->create([
        'tenant_id' => $this->tenant->id,
        'read_at' => $now,
    ]);

    $this->assertInstanceOf(Carbon::class, $notification->read_at);
    $this->assertTrue($notification->isRead());
})->group('NOTIF-DB-09');

it('NOTIF-DB-10: user relation works', function (): void {
    $notification = Notification::factory()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->user->id,
    ]);

    $this->assertNotNull($notification->user);
    $this->assertEquals($this->user->id, $notification->user->id);
})->group('NOTIF-DB-10');

it('NOTIF-DB-11: tenant-wide notification with null user_id is allowed', function (): void {
    $notification = Notification::factory()->tenantWide()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $this->assertNull($notification->user_id);
    $this->assertNotNull($notification->id);
})->group('NOTIF-DB-11');

it('NOTIF-DB-12: title and body persist correctly', function (): void {
    $notification = Notification::factory()->create([
        'tenant_id' => $this->tenant->id,
        'title' => 'Test notification title',
        'body' => 'This is the notification body text.',
    ]);

    $this->assertEquals('Test notification title', $notification->title);
    $this->assertEquals('This is the notification body text.', $notification->body);
})->group('NOTIF-DB-12');

it('NOTIF-DB-13: safe metadata in data JSON', function (): void {
    $safeData = [
        'conversation_id' => (string) Str::uuid(),
        'agent_id' => $this->user->id,
        'event' => 'handoff_requested',
        'route' => '/conversations',
    ];

    $notification = Notification::factory()->create([
        'tenant_id' => $this->tenant->id,
        'data' => $safeData,
    ]);

    $this->assertArrayHasKey('conversation_id', $notification->data);
    $this->assertArrayNotHasKey('phone', $notification->data);
    $this->assertArrayNotHasKey('email', $notification->data);
    $this->assertArrayNotHasKey('message_body', $notification->data);
    $this->assertArrayNotHasKey('api_key', $notification->data);
})->group('NOTIF-DB-13');

it('NOTIF-DB-14: soft delete preserves record', function (): void {
    $notification = Notification::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $id = $notification->id;
    $notification->delete();

    $this->assertNull(Notification::find($id));
    $this->assertNotNull(Notification::withTrashed()->find($id));
})->group('NOTIF-DB-14');

it('NOTIF-DB-15: same type for same user can repeat', function (): void {
    Notification::factory()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->user->id,
        'type' => NotificationType::HandoffRequested,
    ]);

    Notification::factory()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->user->id,
        'type' => NotificationType::HandoffRequested,
    ]);

    $count = Notification::where('tenant_id', $this->tenant->id)
        ->where('user_id', $this->user->id)
        ->where('type', NotificationType::HandoffRequested)
        ->count();

    $this->assertEquals(2, $count);
})->group('NOTIF-DB-15');
