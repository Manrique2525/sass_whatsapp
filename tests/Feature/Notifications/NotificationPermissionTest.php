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
    $this->admin = User::factory()->create();
    $this->agent = User::factory()->create();

    make_tenant_member($this->owner, $this->tenant, 'owner');
    make_tenant_member($this->admin, $this->tenant, 'admin');
    make_tenant_member($this->agent, $this->tenant, 'agent');

    TenantContext::clear();
});

function permNotifIndexUrl(Tenant $tenant): string
{
    return '/api/v1/tenants/'.$tenant->id.'/notifications';
}

function createNotifFor(User $user, Tenant $tenant): Notification
{
    TenantContext::setId($tenant->id);

    try {
        return Notification::query()->create([
            'user_id' => $user->id,
            'type' => NotificationType::HandoffRequested,
            'priority' => NotificationPriority::High,
            'title' => 'Test',
            'body' => 'Test body',
            'data' => ['event' => 'test'],
        ]);
    } finally {
        TenantContext::clear();
    }
}

/*
|--------------------------------------------------------------------------
| NOTIF-PERM-01..06 — Permission Tests
|--------------------------------------------------------------------------
*/

it('NOTIF-PERM-01: owner can view own notifications', function (): void {
    createNotifFor($this->owner, $this->tenant);

    $this->actingAs($this->owner)->getJson(permNotifIndexUrl($this->tenant))
        ->assertOk()
        ->assertJsonCount(1, 'notifications');
});

it('NOTIF-PERM-02: admin can view own notifications', function (): void {
    createNotifFor($this->admin, $this->tenant);

    $this->actingAs($this->admin)->getJson(permNotifIndexUrl($this->tenant))
        ->assertOk()
        ->assertJsonCount(1, 'notifications');
});

it('NOTIF-PERM-03: agent can view own notifications', function (): void {
    createNotifFor($this->agent, $this->tenant);

    $this->actingAs($this->agent)->getJson(permNotifIndexUrl($this->tenant))
        ->assertOk()
        ->assertJsonCount(1, 'notifications');
});

it('NOTIF-PERM-04: unauthenticated returns 401', function (): void {
    $response = $this->getJson(permNotifIndexUrl($this->tenant));

    $response->assertUnauthorized();
});

it('NOTIF-PERM-05: non-member denied', function (): void {
    $stranger = User::factory()->create();

    $response = $this->actingAs($stranger)->getJson(permNotifIndexUrl($this->tenant));

    $response->assertForbidden();
});

it('NOTIF-PERM-06: no ability to see other users notifications', function (): void {
    $otherUser = User::factory()->create();
    make_tenant_member($otherUser, $this->tenant, 'agent');

    createNotifFor($otherUser, $this->tenant);
    createNotifFor($this->owner, $this->tenant);

    $this->actingAs($this->owner)->getJson(permNotifIndexUrl($this->tenant))
        ->assertOk()
        ->assertJsonCount(1, 'notifications');
});
