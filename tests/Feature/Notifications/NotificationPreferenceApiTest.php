<?php

declare(strict_types=1);

use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\TenantUser;
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

function prefUrl(Tenant $tenant): string
{
    return '/api/v1/tenants/'.$tenant->id.'/notification-preferences';
}

it('NOTIF-PREF-API-01: get returns default false', function (): void {
    $response = $this->actingAs($this->owner)
        ->getJson(prefUrl($this->tenant));

    $response->assertOk()
        ->assertJson([
            'email_notifications_enabled' => false,
        ]);
});

it('NOTIF-PREF-API-02: enable email notifications', function (): void {
    $response = $this->actingAs($this->owner)
        ->patchJson(prefUrl($this->tenant), [
            'email_notifications_enabled' => true,
        ]);

    $response->assertOk()
        ->assertJson([
            'message' => 'Preferencia actualizada.',
            'email_notifications_enabled' => true,
        ]);

    $get = $this->actingAs($this->owner)
        ->getJson(prefUrl($this->tenant));

    $get->assertOk()
        ->assertJson([
            'email_notifications_enabled' => true,
        ]);
});

it('NOTIF-PREF-API-03: disable email notifications', function (): void {
    TenantUser::query()
        ->where('tenant_id', $this->tenant->id)
        ->where('user_id', $this->owner->id)
        ->update(['email_notifications_enabled' => true]);

    $response = $this->actingAs($this->owner)
        ->patchJson(prefUrl($this->tenant), [
            'email_notifications_enabled' => false,
        ]);

    $response->assertOk()
        ->assertJson([
            'email_notifications_enabled' => false,
        ]);

    $get = $this->actingAs($this->owner)
        ->getJson(prefUrl($this->tenant));

    $get->assertOk()
        ->assertJson([
            'email_notifications_enabled' => false,
        ]);
});

it('NOTIF-PREF-API-04: invalid type returns 422', function (): void {
    $response = $this->actingAs($this->owner)
        ->patchJson(prefUrl($this->tenant), [
            'email_notifications_enabled' => 'not-a-boolean',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['email_notifications_enabled']);
});

it('NOTIF-PREF-API-05: unauthenticated returns 401', function (): void {
    $response = $this->getJson(prefUrl($this->tenant));

    $response->assertUnauthorized();
});

it('NOTIF-PREF-API-06: cross-tenant preference blocked', function (): void {
    $otherTenant = Tenant::factory()->create();

    $response = $this->actingAs($this->owner)
        ->getJson(prefUrl($otherTenant));

    $response->assertNotFound();
});

it('NOTIF-PREF-API-07: response does not expose tenant_id or user_id', function (): void {
    $response = $this->actingAs($this->owner)
        ->getJson(prefUrl($this->tenant));

    $response->assertOk();

    $data = $response->json();
    expect($data)->not->toHaveKey('tenant_id');
    expect($data)->not->toHaveKey('user_id');
    expect($data)->not->toHaveKey('id');
});

it('NOTIF-PREF-API-08: preference persists per tenant', function (): void {
    $tenantB = Tenant::factory()->create();
    TenantContext::setId($tenantB->id);
    make_tenant_member($this->owner, $tenantB, 'owner');
    TenantContext::clear();

    $this->owner->forceFill(['current_tenant_id' => $this->tenant->id])->save();

    $this->actingAs($this->owner)
        ->patchJson(prefUrl($this->tenant), [
            'email_notifications_enabled' => true,
        ])->assertOk();

    $this->owner->forceFill(['current_tenant_id' => $tenantB->id])->save();

    $this->actingAs($this->owner)
        ->patchJson(prefUrl($tenantB), [
            'email_notifications_enabled' => false,
        ])->assertOk();

    $this->owner->forceFill(['current_tenant_id' => $this->tenant->id])->save();

    $this->actingAs($this->owner)
        ->getJson(prefUrl($this->tenant))
        ->assertJson(['email_notifications_enabled' => true]);

    $this->owner->forceFill(['current_tenant_id' => $tenantB->id])->save();

    $this->actingAs($this->owner)
        ->getJson(prefUrl($tenantB))
        ->assertJson(['email_notifications_enabled' => false]);
});
