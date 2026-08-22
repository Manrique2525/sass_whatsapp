<?php

declare(strict_types=1);

use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| NotificationRouting Tests (FASE 22 U5)
|--------------------------------------------------------------------------
|
| NOTIF-RT-01..04 — Route ordering and endpoint correctness.
|
*/

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->owner = User::factory()->create();
    make_tenant_member($this->owner, $this->tenant, 'owner');
});

/*
|--------------------------------------------------------------------------
| NOTIF-RT-01 — unread-count route resolves correctly
|--------------------------------------------------------------------------
*/
it('resolves unread-count route without conflict', function (): void {
    $response = $this->actingAs($this->owner)->getJson(
        "/api/v1/tenants/{$this->tenant->id}/notifications/unread-count"
    );

    $response->assertOk();
    $response->assertJsonStructure(['unread_count']);
});

/*
|--------------------------------------------------------------------------
| NOTIF-RT-02 — unread-count returns integer
|--------------------------------------------------------------------------
*/
it('returns integer unread count', function (): void {
    $response = $this->actingAs($this->owner)->getJson(
        "/api/v1/tenants/{$this->tenant->id}/notifications/unread-count"
    );

    $response->assertOk();
    $response->assertJsonPath('unread_count', fn ($val) => is_int($val));
});

/*
|--------------------------------------------------------------------------
| NOTIF-RT-03 — unread-count with no notifications returns 0
|--------------------------------------------------------------------------
*/
it('returns zero when no notifications exist', function (): void {
    $response = $this->actingAs($this->owner)->getJson(
        "/api/v1/tenants/{$this->tenant->id}/notifications/unread-count"
    );

    $response->assertOk();
    $response->assertJsonPath('unread_count', 0);
});

/*
|--------------------------------------------------------------------------
| NOTIF-RT-04 — notifications index route still works
|--------------------------------------------------------------------------
*/
it('resolves notifications index route', function (): void {
    $response = $this->actingAs($this->owner)->getJson(
        "/api/v1/tenants/{$this->tenant->id}/notifications"
    );

    $response->assertOk();
    $response->assertJsonStructure(['notifications', 'meta', 'counts']);
});
