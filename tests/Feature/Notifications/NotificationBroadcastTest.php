<?php

declare(strict_types=1);

use App\Application\Notifications\Services\NotificationService;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Events\NotificationCreated;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| NotificationBroadcast Tests (FASE 22 U5)
|--------------------------------------------------------------------------
|
| NOTIF-BC-01..12 — NotificationCreated event + personal channel auth.
| Corren en SQLite :memory:.
|
*/

final class TestNotificationBroadcaster extends Broadcaster
{
    public function auth($request)
    {
        $user = $request->user();

        if ($user === null) {
            throw new AuthorizationException('Unauthenticated.');
        }

        $channelName = str_replace(['private-', 'presence-'], '', (string) $request->channel_name);

        $this->verifyUserCanAccessChannel($request, $channelName);

        return $this->validAuthenticationResponse($request, true);
    }

    public function validAuthenticationResponse($request, $result)
    {
        return response()->json(['auth' => 'ok']);
    }

    public function broadcast(array $channels, $event, array $payload = [])
    {
        // no-op for tests
    }
}

function register_notification_test_channel(): void
{
    Broadcast::extend('test-auth-notif', fn (): TestNotificationBroadcaster => new TestNotificationBroadcaster);

    config(['broadcasting.default' => 'test-auth-notif']);
    config(['broadcasting.connections.test-auth-notif' => ['driver' => 'test-auth-notif']]);

    Broadcast::connection('test-auth-notif')->channel('tenant.{tenantId}.users.{userId}.notifications', function (User $user, string $tenantId, string $userId): bool {
        return (string) $user->id === $userId && $user->belongsToTenantById($tenantId);
    });
}

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->otherTenant = Tenant::factory()->create();

    $this->owner = User::factory()->create();
    make_tenant_member($this->owner, $this->tenant, 'owner');

    $this->otherUser = User::factory()->create();
    make_tenant_member($this->otherUser, $this->tenant, 'agent');

    $this->crossTenantUser = User::factory()->create();
    make_tenant_member($this->crossTenantUser, $this->otherTenant, 'owner');

    $this->contact = make_contact($this->tenant);

    TenantContext::setId($this->tenant->id);

    $this->conversation = Conversation::create([
        'tenant_id' => $this->tenant->id,
        'contact_id' => $this->contact->id,
        'status' => 'open',
    ]);

    TenantContext::clear();
});

afterEach(function (): void {
    TenantContext::clear();
});

/*
|--------------------------------------------------------------------------
| NOTIF-BC-01 — NotificationCreated implements ShouldBroadcast
|--------------------------------------------------------------------------
*/
it('implements ShouldBroadcast', function (): void {
    TenantContext::setId($this->tenant->id);

    $notification = Notification::query()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->owner->id,
        'type' => 'handoff_requested',
        'priority' => 'high',
        'title' => 'Test',
        'body' => 'Test body',
        'data' => ['conversation_id' => $this->conversation->id],
    ]);

    $event = new NotificationCreated($notification);

    expect($event)->toBeInstanceOf(ShouldBroadcast::class);
});

/*
|--------------------------------------------------------------------------
| NOTIF-BC-02 — Channel name format: private-tenant.{id}.users.{userId}.notifications
|--------------------------------------------------------------------------
*/
it('broadcasts on personal notification channel', function (): void {
    TenantContext::setId($this->tenant->id);

    $notification = Notification::query()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->owner->id,
        'type' => 'handoff_requested',
        'priority' => 'high',
        'title' => 'Test',
        'body' => 'Test body',
        'data' => ['conversation_id' => $this->conversation->id],
    ]);

    $event = new NotificationCreated($notification);
    $channels = $event->broadcastOn();

    expect($channels)->toHaveCount(1);
    expect($channels[0]->name)->toBe("private-tenant.{$this->tenant->id}.users.{$this->owner->id}.notifications");
});

/*
|--------------------------------------------------------------------------
| NOTIF-BC-03 — broadcastAs returns 'NotificationCreated'
|--------------------------------------------------------------------------
*/
it('broadcasts as NotificationCreated event', function (): void {
    TenantContext::setId($this->tenant->id);

    $notification = Notification::query()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->owner->id,
        'type' => 'handoff_requested',
        'priority' => 'high',
        'title' => 'Test',
        'body' => 'Test body',
        'data' => ['conversation_id' => $this->conversation->id],
    ]);

    $event = new NotificationCreated($notification);

    expect($event->broadcastAs())->toBe('NotificationCreated');
});

/*
|--------------------------------------------------------------------------
| NOTIF-BC-04 — broadcastWith contains safe notification data
|--------------------------------------------------------------------------
*/
it('broadcasts safe notification payload', function (): void {
    TenantContext::setId($this->tenant->id);

    $notification = Notification::query()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->owner->id,
        'type' => 'handoff_requested',
        'priority' => 'high',
        'title' => 'Test title',
        'body' => 'Test body',
        'data' => ['conversation_id' => $this->conversation->id, 'event' => 'handoff_requested'],
    ]);

    $event = new NotificationCreated($notification);
    $payload = $event->broadcastWith();

    expect($payload)->toHaveKey('notification');
    expect($payload['notification'])->toHaveKeys(['id', 'type', 'priority', 'title', 'body', 'data', 'read_at', 'created_at']);
    expect($payload['notification']['title'])->toBe('Test title');
    expect($payload['notification']['body'])->toBe('Test body');
});

/*
|--------------------------------------------------------------------------
| NOTIF-BC-05 — broadcastWith does NOT expose tenant_id or user_id
|--------------------------------------------------------------------------
*/
it('does not expose tenant_id or user_id in broadcast payload', function (): void {
    TenantContext::setId($this->tenant->id);

    $notification = Notification::query()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->owner->id,
        'type' => 'handoff_requested',
        'priority' => 'high',
        'title' => 'Test',
        'body' => 'Test body',
        'data' => ['conversation_id' => $this->conversation->id],
    ]);

    $event = new NotificationCreated($notification);
    $payload = $event->broadcastWith();

    expect($payload['notification'])->not->toHaveKey('tenant_id');
    expect($payload['notification'])->not->toHaveKey('user_id');
});

/*
|--------------------------------------------------------------------------
| NOTIF-BC-06 — afterCommit is true
|--------------------------------------------------------------------------
*/
it('has afterCommit true', function (): void {
    TenantContext::setId($this->tenant->id);

    $notification = Notification::query()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->owner->id,
        'type' => 'handoff_requested',
        'priority' => 'high',
        'title' => 'Test',
        'body' => 'Test body',
        'data' => ['conversation_id' => $this->conversation->id],
    ]);

    $event = new NotificationCreated($notification);

    expect($event->afterCommit)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| NOTIF-BC-07 — Channel auth: same tenant user OK
|--------------------------------------------------------------------------
*/
it('authorizes same tenant user on personal channel', function (): void {
    register_notification_test_channel();

    $channelName = "tenant.{$this->tenant->id}.users.{$this->owner->id}.notifications";

    $this->actingAs($this->owner)->postJson('/broadcasting/auth', [
        'channel_name' => $channelName,
    ])->assertOk();
});

/*
|--------------------------------------------------------------------------
| NOTIF-BC-08 — Channel auth: different user in same tenant → 403
|--------------------------------------------------------------------------
*/
it('denies different user on personal channel', function (): void {
    register_notification_test_channel();

    $channelName = "tenant.{$this->tenant->id}.users.{$this->owner->id}.notifications";

    $this->actingAs($this->otherUser)->postJson('/broadcasting/auth', [
        'channel_name' => $channelName,
    ])->assertStatus(403);
});

/*
|--------------------------------------------------------------------------
| NOTIF-BC-09 — Channel auth: user from different tenant → 403
|--------------------------------------------------------------------------
*/
it('denies cross-tenant user on personal channel', function (): void {
    register_notification_test_channel();

    $channelName = "tenant.{$this->tenant->id}.users.{$this->owner->id}.notifications";

    $this->actingAs($this->crossTenantUser)->postJson('/broadcasting/auth', [
        'channel_name' => $channelName,
    ])->assertStatus(403);
});

/*
|--------------------------------------------------------------------------
| NOTIF-BC-10 — Channel auth: unauthenticated user → 403
|--------------------------------------------------------------------------
*/
it('denies unauthenticated access to personal channel', function (): void {
    register_notification_test_channel();

    $channelName = "tenant.{$this->tenant->id}.users.{$this->owner->id}.notifications";

    $response = $this->postJson('/broadcasting/auth', [
        'channel_name' => $channelName,
    ]);

    $response->assertStatus(403);
});

/*
|--------------------------------------------------------------------------
| NOTIF-BC-11 — Service dispatches NotificationCreated
|--------------------------------------------------------------------------
*/
it('dispatches NotificationCreated when creating notification', function (): void {
    Event::fake([NotificationCreated::class]);

    $service = app(NotificationService::class);

    TenantContext::setId($this->tenant->id);

    $notification = $service->handleConversationAssigned(
        $this->tenant,
        $this->conversation,
        $this->owner->id,
    );

    Event::assertDispatched(NotificationCreated::class, function (NotificationCreated $event) use ($notification): bool {
        return $event->notification->id === $notification->id;
    });
});

/*
|--------------------------------------------------------------------------
| NOTIF-BC-12 — Service dispatches NotificationCreated per member for handoff
|--------------------------------------------------------------------------
*/
it('dispatches NotificationCreated for each member on handoff', function (): void {
    Event::fake([NotificationCreated::class]);

    $service = app(NotificationService::class);

    TenantContext::setId($this->tenant->id);

    $notifications = $service->handleHandoffRequested($this->tenant, $this->conversation);

    Event::assertDispatched(NotificationCreated::class, count($notifications));
});
