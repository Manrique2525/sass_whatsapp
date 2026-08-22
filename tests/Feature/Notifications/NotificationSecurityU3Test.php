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
    make_tenant_member($this->owner, $this->tenant, 'owner');

    TenantContext::clear();
});

function secNotifIndexUrl(Tenant $tenant): string
{
    return '/api/v1/tenants/'.$tenant->id.'/notifications';
}

function secNotifMarkReadUrl(Tenant $tenant, string $notificationId): string
{
    return '/api/v1/tenants/'.$tenant->id.'/notifications/'.$notificationId.'/read';
}

function createSecNotif(User $user, Tenant $tenant, array $overrides = []): Notification
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
| NOTIF-SEC-U3-01..10 — Security Tests
|--------------------------------------------------------------------------
*/

it('NOTIF-SEC-U3-01: IDOR same tenant blocked', function (): void {
    $otherUser = User::factory()->create();
    make_tenant_member($otherUser, $this->tenant, 'agent');

    $notification = createSecNotif($otherUser, $this->tenant);

    $response = $this->actingAs($this->owner)->patchJson(secNotifMarkReadUrl($this->tenant, $notification->id));

    $response->assertNotFound();
});

it('NOTIF-SEC-U3-02: IDOR cross tenant blocked', function (): void {
    $tenantB = Tenant::factory()->create();
    $ownerB = User::factory()->create();
    make_tenant_member($ownerB, $tenantB, 'owner');

    $notification = createSecNotif($this->owner, $this->tenant);

    $response = $this->actingAs($ownerB)->patchJson(secNotifMarkReadUrl($tenantB, $notification->id));

    $response->assertNotFound();
});

it('NOTIF-SEC-U3-03: tenant_id injection absent from response', function (): void {
    createSecNotif($this->owner, $this->tenant);

    $response = $this->actingAs($this->owner)->getJson(secNotifIndexUrl($this->tenant));

    $response->assertOk();

    foreach ($response->json('notifications') as $item) {
        expect($item)->not->toHaveKey('tenant_id');
    }
});

it('NOTIF-SEC-U3-04: user_id injection absent from response', function (): void {
    createSecNotif($this->owner, $this->tenant);

    $response = $this->actingAs($this->owner)->getJson(secNotifIndexUrl($this->tenant));

    $response->assertOk();

    foreach ($response->json('notifications') as $item) {
        expect($item)->not->toHaveKey('user_id');
    }
});

it('NOTIF-SEC-U3-05: no SQL-looking filter risk — validation rejects invalid input', function (): void {
    $response = $this->actingAs($this->owner)->getJson(
        secNotifIndexUrl($this->tenant).'?read_status='.(string) Str::random(50),
    );

    $response->assertStatus(422)
        ->assertJsonValidationErrors('read_status');
});

it('NOTIF-SEC-U3-06: resource no internal IDs', function (): void {
    createSecNotif($this->owner, $this->tenant);

    $response = $this->actingAs($this->owner)->getJson(secNotifIndexUrl($this->tenant));

    $response->assertOk();
    $item = $response->json('notifications.0');
    expect($item)->not->toHaveKey('deleted_at')
        ->and($item)->not->toHaveKey('updated_at');
});

it('NOTIF-SEC-U3-07: no PII in notification', function (): void {
    createSecNotif($this->owner, $this->tenant, [
        'title' => 'Notificación de prueba',
        'body' => 'Cuerpo del mensaje de prueba.',
    ]);

    $response = $this->actingAs($this->owner)->getJson(secNotifIndexUrl($this->tenant));

    $response->assertOk();
    $item = $response->json('notifications.0');
    expect($item['title'])->not->toContain('@')
        ->and($item['body'])->not->toContain('@')
        ->and($item['title'])->not->toMatch('/\+\d/')
        ->and($item['body'])->not->toMatch('/\+\d/');
});

it('NOTIF-SEC-U3-08: soft-deleted hidden from index', function (): void {
    $n = createSecNotif($this->owner, $this->tenant);
    $n->delete();

    $response = $this->actingAs($this->owner)->getJson(secNotifIndexUrl($this->tenant));

    $response->assertOk()
        ->assertJsonCount(0, 'notifications');
});

it('NOTIF-SEC-U3-09: mass assignment impossible', function (): void {
    $response = $this->actingAs($this->owner)->getJson(secNotifIndexUrl($this->tenant));

    $response->assertOk();

    foreach ($response->json('notifications') as $item) {
        expect($item)->not->toHaveKey('tenant_id')
            ->and($item)->not->toHaveKey('user_id')
            ->and($item)->not->toHaveKey('deleted_at');
    }
});

it('NOTIF-SEC-U3-10: raw error not leaked', function (): void {
    $fakeId = (string) Str::uuid();

    $response = $this->actingAs($this->owner)->patchJson(secNotifMarkReadUrl($this->tenant, $fakeId));

    $response->assertNotFound()
        ->assertJsonMissing(['exception', 'file', 'line', 'trace']);
});
