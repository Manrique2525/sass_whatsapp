<?php

declare(strict_types=1);

use App\Domain\Notifications\Enums\NotificationPriority;
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

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    TenantContext::setId($this->tenant->id);

    $this->owner = User::factory()->create();
    $this->agent = User::factory()->create();

    make_tenant_member($this->owner, $this->tenant, 'owner');
    make_tenant_member($this->agent, $this->tenant, 'agent');

    TenantContext::clear();
});

function notifIndexUrl(Tenant $tenant, array $params = []): string
{
    $base = '/api/v1/tenants/'.$tenant->id.'/notifications';

    if ($params === []) {
        return $base;
    }

    return $base.'?'.http_build_query($params);
}

function notifMarkReadUrl(Tenant $tenant, string $notificationId): string
{
    return '/api/v1/tenants/'.$tenant->id.'/notifications/'.$notificationId.'/read';
}

function notifMarkAllReadUrl(Tenant $tenant): string
{
    return '/api/v1/tenants/'.$tenant->id.'/notifications/read-all';
}

function createNotificationFor(User $user, Tenant $tenant, array $overrides = []): Notification
{
    TenantContext::setId($tenant->id);

    try {
        return Notification::query()->create(array_merge([
            'user_id' => $user->id,
            'type' => NotificationType::HandoffRequested,
            'priority' => NotificationPriority::High,
            'title' => 'Test notification',
            'body' => 'Test body',
            'data' => ['event' => 'test'],
        ], $overrides));
    } finally {
        TenantContext::clear();
    }
}

/*
|--------------------------------------------------------------------------
| NOTIF-API-01..15 — API Behavior Tests
|--------------------------------------------------------------------------
*/

it('NOTIF-API-01: list returns notifications for authenticated user', function (): void {
    $n1 = createNotificationFor($this->owner, $this->tenant);
    createNotificationFor($this->agent, $this->tenant);

    $response = $this->actingAs($this->owner)->getJson(notifIndexUrl($this->tenant));

    $response->assertOk()
        ->assertJsonCount(1, 'notifications')
        ->assertJsonPath('notifications.0.id', $n1->id)
        ->assertJsonStructure([
            'notifications' => [['id', 'type', 'priority', 'title', 'body', 'data', 'read_at', 'created_at']],
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            'counts' => ['unread'],
        ]);
});

it('NOTIF-API-02: pagination works correctly', function (): void {
    for ($i = 0; $i < 5; $i++) {
        createNotificationFor($this->owner, $this->tenant);
    }

    $response = $this->actingAs($this->owner)->getJson(notifIndexUrl($this->tenant, ['per_page' => 2]));

    $response->assertOk()
        ->assertJsonCount(2, 'notifications')
        ->assertJsonPath('meta.total', 5)
        ->assertJsonPath('meta.last_page', 3)
        ->assertJsonPath('meta.per_page', 2);
});

it('NOTIF-API-03: default order is created_at DESC, id DESC', function (): void {
    $n1 = createNotificationFor($this->owner, $this->tenant);
    $n2 = createNotificationFor($this->owner, $this->tenant);

    $response = $this->actingAs($this->owner)->getJson(notifIndexUrl($this->tenant));

    $response->assertOk()
        ->assertJsonPath('notifications.0.id', $n2->id)
        ->assertJsonPath('notifications.1.id', $n1->id);
});

it('NOTIF-API-04: unread filter returns only unread', function (): void {
    $unread = createNotificationFor($this->owner, $this->tenant);
    createNotificationFor($this->owner, $this->tenant, ['read_at' => now()]);

    $response = $this->actingAs($this->owner)->getJson(notifIndexUrl($this->tenant, ['read_status' => 'unread']));

    $response->assertOk()
        ->assertJsonCount(1, 'notifications')
        ->assertJsonPath('notifications.0.id', $unread->id);
});

it('NOTIF-API-05: read filter returns only read', function (): void {
    createNotificationFor($this->owner, $this->tenant);
    $read = createNotificationFor($this->owner, $this->tenant, ['read_at' => now()]);

    $response = $this->actingAs($this->owner)->getJson(notifIndexUrl($this->tenant, ['read_status' => 'read']));

    $response->assertOk()
        ->assertJsonCount(1, 'notifications')
        ->assertJsonPath('notifications.0.id', $read->id);
});

it('NOTIF-API-06: counts unread in response', function (): void {
    createNotificationFor($this->owner, $this->tenant);
    createNotificationFor($this->owner, $this->tenant);
    createNotificationFor($this->owner, $this->tenant, ['read_at' => now()]);

    $response = $this->actingAs($this->owner)->getJson(notifIndexUrl($this->tenant));

    $response->assertOk()
        ->assertJsonPath('counts.unread', 2)
        ->assertJsonPath('meta.total', 3);
});

it('NOTIF-API-07: mark read succeeds', function (): void {
    $notification = createNotificationFor($this->owner, $this->tenant);

    $response = $this->actingAs($this->owner)->patchJson(notifMarkReadUrl($this->tenant, $notification->id));

    $response->assertOk()
        ->assertJsonPath('notification.id', $notification->id);

    expect($response->json('notification.read_at'))->not->toBeNull();
});

it('NOTIF-API-08: mark read is idempotent', function (): void {
    $notification = createNotificationFor($this->owner, $this->tenant);

    $this->actingAs($this->owner)->patchJson(notifMarkReadUrl($this->tenant, $notification->id))->assertOk();
    $originalReadAt = $notification->fresh()->read_at;

    $this->actingAs($this->owner)->patchJson(notifMarkReadUrl($this->tenant, $notification->id))->assertOk();
    $secondReadAt = $notification->fresh()->read_at;

    expect($originalReadAt->timestamp)->toBe($secondReadAt->timestamp);
});

it('NOTIF-API-09: mark all read succeeds', function (): void {
    createNotificationFor($this->owner, $this->tenant);
    createNotificationFor($this->owner, $this->tenant);
    createNotificationFor($this->owner, $this->tenant, ['read_at' => now()]);

    $response = $this->actingAs($this->owner)->postJson(notifMarkAllReadUrl($this->tenant));

    $response->assertOk()
        ->assertJsonPath('updated', 2)
        ->assertJsonPath('counts.unread', 0);
});

it('NOTIF-API-10: mark all read is idempotent', function (): void {
    createNotificationFor($this->owner, $this->tenant);

    $this->actingAs($this->owner)->postJson(notifMarkAllReadUrl($this->tenant))->assertOk();

    $response = $this->actingAs($this->owner)->postJson(notifMarkAllReadUrl($this->tenant));

    $response->assertOk()
        ->assertJsonPath('updated', 0)
        ->assertJsonPath('counts.unread', 0);
});

it('NOTIF-API-11: mark read returns 404 for nonexistent notification', function (): void {
    $fakeId = (string) Str::uuid();

    $response = $this->actingAs($this->owner)->patchJson(notifMarkReadUrl($this->tenant, $fakeId));

    $response->assertNotFound();
});

it('NOTIF-API-12: mark read returns 404 for other users notification', function (): void {
    $notification = createNotificationFor($this->agent, $this->tenant);

    $response = $this->actingAs($this->owner)->patchJson(notifMarkReadUrl($this->tenant, $notification->id));

    $response->assertNotFound();
});

it('NOTIF-API-13: resource does not expose tenant_id', function (): void {
    createNotificationFor($this->owner, $this->tenant);

    $response = $this->actingAs($this->owner)->getJson(notifIndexUrl($this->tenant));

    $response->assertOk();

    foreach ($response->json('notifications') as $item) {
        expect($item)->not->toHaveKey('tenant_id');
    }
});

it('NOTIF-API-14: resource does not expose user_id', function (): void {
    createNotificationFor($this->owner, $this->tenant);

    $response = $this->actingAs($this->owner)->getJson(notifIndexUrl($this->tenant));

    $response->assertOk();

    foreach ($response->json('notifications') as $item) {
        expect($item)->not->toHaveKey('user_id');
    }
});

it('NOTIF-API-15: data field contains safe metadata only', function (): void {
    createNotificationFor($this->owner, $this->tenant, ['data' => ['conversation_id' => 'abc-123', 'event' => 'handoff_requested']]);

    $response = $this->actingAs($this->owner)->getJson(notifIndexUrl($this->tenant));

    $response->assertOk();
    $data = $response->json('notifications.0.data');
    expect($data)->toHaveKeys(['conversation_id', 'event']);
});
