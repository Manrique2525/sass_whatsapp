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
    $this->tenantA = Tenant::factory()->create();
    $this->tenantB = Tenant::factory()->create();

    TenantContext::setId($this->tenantA->id);

    $this->ownerA = User::factory()->create();
    $this->ownerB = User::factory()->create();
    $this->agentA = User::factory()->create();

    make_tenant_member($this->ownerA, $this->tenantA, 'owner');
    make_tenant_member($this->agentA, $this->tenantA, 'agent');
    make_tenant_member($this->ownerB, $this->tenantB, 'owner');

    TenantContext::clear();
});

function mtNotifIndexUrl(Tenant $tenant): string
{
    return '/api/v1/tenants/'.$tenant->id.'/notifications';
}

function mtNotifMarkReadUrl(Tenant $tenant, string $notificationId): string
{
    return '/api/v1/tenants/'.$tenant->id.'/notifications/'.$notificationId.'/read';
}

function mtNotifMarkAllReadUrl(Tenant $tenant): string
{
    return '/api/v1/tenants/'.$tenant->id.'/notifications/read-all';
}

function createNotif(User $user, Tenant $tenant, array $overrides = []): Notification
{
    TenantContext::setId($tenant->id);

    try {
        return Notification::query()->create(array_merge([
            'user_id' => $user->id,
            'type' => NotificationType::HandoffRequested,
            'priority' => NotificationPriority::High,
            'title' => 'Test',
            'body' => 'Test body',
            'data' => ['event' => 'test'],
        ], $overrides));
    } finally {
        TenantContext::clear();
    }
}

/*
|--------------------------------------------------------------------------
| NOTIF-MT-U3-01..10 — Multi-Tenancy Tests
|--------------------------------------------------------------------------
*/

it('NOTIF-MT-U3-01: tenant A lists only A/user notifications', function (): void {
    $n1 = createNotif($this->ownerA, $this->tenantA);
    createNotif($this->ownerB, $this->tenantB);

    $response = $this->actingAs($this->ownerA)->getJson(mtNotifIndexUrl($this->tenantA));

    $response->assertOk()
        ->assertJsonCount(1, 'notifications')
        ->assertJsonPath('notifications.0.id', $n1->id);
});

it('NOTIF-MT-U3-02: tenant A cannot see B tenant notifications', function (): void {
    createNotif($this->ownerB, $this->tenantB);

    $response = $this->actingAs($this->ownerA)->getJson(mtNotifIndexUrl($this->tenantA));

    $response->assertOk()
        ->assertJsonCount(0, 'notifications');
});

it('NOTIF-MT-U3-03: user A cannot see user B same tenant notifications', function (): void {
    createNotif($this->agentA, $this->tenantA);

    $response = $this->actingAs($this->ownerA)->getJson(mtNotifIndexUrl($this->tenantA));

    $response->assertOk()
        ->assertJsonCount(0, 'notifications');
});

it('NOTIF-MT-U3-04: user A cannot mark B notification as read', function (): void {
    $notification = createNotif($this->agentA, $this->tenantA);

    $response = $this->actingAs($this->ownerA)->patchJson(mtNotifMarkReadUrl($this->tenantA, $notification->id));

    $response->assertNotFound();
});

it('NOTIF-MT-U3-05: mark-all touches only own user', function (): void {
    createNotif($this->ownerA, $this->tenantA);
    createNotif($this->agentA, $this->tenantA);

    $this->actingAs($this->ownerA)->postJson(mtNotifMarkAllReadUrl($this->tenantA))->assertOk();

    $agentNotif = $notification = Notification::query()->withoutTenantScope()
        ->where('tenant_id', $this->tenantA->id)
        ->where('user_id', $this->agentA->id)
        ->first();

    expect($agentNotif->read_at)->toBeNull();
});

it('NOTIF-MT-U3-06: tenant_id injection via URL is impossible', function (): void {
    $notification = createNotif($this->ownerA, $this->tenantA);

    $response = $this->actingAs($this->ownerA)->getJson(mtNotifIndexUrl($this->tenantB));

    $response->assertNotFound();
});

it('NOTIF-MT-U3-07: malformed UUID returns 404 not oracle', function (): void {
    $response = $this->actingAs($this->ownerA)->patchJson(
        '/api/v1/tenants/'.$this->tenantA->id.'/notifications/not-a-uuid/read',
    );

    $response->assertNotFound();
});

it('NOTIF-MT-U3-08: inactive membership denied', function (): void {
    $inactive = User::factory()->create();
    $inactive->tenants()->attach($this->tenantA, [
        'role' => 'agent',
        'status' => 'inactive',
        'joined_at' => now(),
    ]);
    $inactive->forceFill(['current_tenant_id' => $this->tenantA->id])->save();

    createNotif($inactive, $this->tenantA);

    $response = $this->actingAs($inactive)->getJson(mtNotifIndexUrl($this->tenantA));

    $response->assertForbidden();
});

it('NOTIF-MT-U3-09: cross-tenant context mismatch no leak', function (): void {
    $notification = createNotif($this->ownerA, $this->tenantA);

    $response = $this->actingAs($this->ownerA)->patchJson(mtNotifMarkReadUrl($this->tenantB, $notification->id));

    $response->assertNotFound();
});

it('NOTIF-MT-U3-10: soft-deleted notifications excluded', function (): void {
    $notification = createNotif($this->ownerA, $this->tenantA);
    $notification->delete();

    $response = $this->actingAs($this->ownerA)->getJson(mtNotifIndexUrl($this->tenantA));

    $response->assertOk()
        ->assertJsonCount(0, 'notifications');
});
