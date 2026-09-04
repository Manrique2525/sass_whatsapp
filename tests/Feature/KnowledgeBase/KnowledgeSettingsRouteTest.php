<?php

declare(strict_types=1);

use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('KB-UI-01: authenticated tenant member can render Knowledge settings', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create(['email_verified_at' => now()]);
    make_tenant_member($owner, $tenant, 'owner');

    $this->actingAs($owner)
        ->get('/settings/knowledge')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->component('Settings/Knowledge'));
});

test('KB-UI-02: non-member cannot render Knowledge settings', function (): void {
    $tenant = Tenant::factory()->create();
    $outsider = User::factory()->create(['email_verified_at' => now()]);
    $outsider->forceFill(['current_tenant_id' => $tenant->id])->save();

    $this->actingAs($outsider)
        ->get('/settings/knowledge')
        ->assertStatus(403);
});
