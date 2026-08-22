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

function mtPrefUrl(Tenant $tenant): string
{
    return '/api/v1/tenants/'.$tenant->id.'/notification-preferences';
}

it('NOTIF-PREF-MT-01: user A tenant A reads own preference', function (): void {
    $response = $this->actingAs($this->owner)
        ->getJson(mtPrefUrl($this->tenant));

    $response->assertOk()
        ->assertJson(['email_notifications_enabled' => false]);
});

it('NOTIF-PREF-MT-02: same user tenant B independent preference', function (): void {
    $tenantB = Tenant::factory()->create();
    TenantContext::setId($tenantB->id);
    make_tenant_member($this->owner, $tenantB, 'owner');
    TenantContext::clear();

    $this->owner->forceFill(['current_tenant_id' => $this->tenant->id])->save();

    $this->actingAs($this->owner)
        ->patchJson(mtPrefUrl($this->tenant), ['email_notifications_enabled' => true])
        ->assertOk();

    $this->owner->forceFill(['current_tenant_id' => $tenantB->id])->save();

    $this->actingAs($this->owner)
        ->patchJson(mtPrefUrl($tenantB), ['email_notifications_enabled' => false])
        ->assertOk();

    $this->owner->forceFill(['current_tenant_id' => $this->tenant->id])->save();

    $this->actingAs($this->owner)
        ->getJson(mtPrefUrl($this->tenant))
        ->assertJson(['email_notifications_enabled' => true]);

    $this->owner->forceFill(['current_tenant_id' => $tenantB->id])->save();

    $this->actingAs($this->owner)
        ->getJson(mtPrefUrl($tenantB))
        ->assertJson(['email_notifications_enabled' => false]);
});

it('NOTIF-PREF-MT-03: user A cannot edit user B preference', function (): void {
    $response = $this->actingAs($this->agent)
        ->getJson(mtPrefUrl($this->tenant));

    $response->assertOk()
        ->assertJson(['email_notifications_enabled' => false]);

    $response2 = $this->actingAs($this->admin)
        ->getJson(mtPrefUrl($this->tenant));

    $response2->assertOk()
        ->assertJson(['email_notifications_enabled' => false]);
});

it('NOTIF-PREF-MT-04: tenant_id injection ignored — other tenant returns 404', function (): void {
    $otherTenant = Tenant::factory()->create();

    $response = $this->actingAs($this->owner)
        ->patchJson(mtPrefUrl($otherTenant), ['email_notifications_enabled' => true]);

    $response->assertNotFound();
});

it('NOTIF-PREF-MT-05: inactive membership denied', function (): void {
    TenantUser::query()
        ->where('tenant_id', $this->tenant->id)
        ->where('user_id', $this->agent->id)
        ->update(['status' => 'disabled']);

    $response = $this->actingAs($this->agent)
        ->getJson(mtPrefUrl($this->tenant));

    $response->assertForbidden();
});

it('NOTIF-PREF-MT-06: TenantContext no leak — different context returns correct result', function (): void {
    $tenantB = Tenant::factory()->create();
    TenantContext::setId($tenantB->id);
    make_tenant_member($this->owner, $tenantB, 'owner');
    TenantContext::clear();

    $this->owner->forceFill(['current_tenant_id' => $this->tenant->id])->save();

    $this->actingAs($this->owner)
        ->patchJson(mtPrefUrl($this->tenant), ['email_notifications_enabled' => true])
        ->assertOk();

    $this->owner->forceFill(['current_tenant_id' => $tenantB->id])->save();

    $this->actingAs($this->owner)
        ->getJson(mtPrefUrl($tenantB))
        ->assertJson(['email_notifications_enabled' => false]);
});
