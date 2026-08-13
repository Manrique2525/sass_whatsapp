<?php

declare(strict_types=1);

use App\Application\Users\Services\InvitationService;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\InvitationStatus;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Models\TenantInvitation;
use App\Domain\Users\Models\TenantUser;
use App\Domain\Users\Models\User;
use App\Domain\Users\Notifications\InvitationNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| INVITATIONS 9-14: ciclo de vida de las invitaciones (ADR-027)
|--------------------------------------------------------------------------
*/

test('INV-9: invitar crea una invitación pendiente, hashea el token y notifica el enlace', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $token = invitation_token(fn () => app(InvitationService::class)->invite(
        $owner,
        $tenant,
        'invited@example.com',
        UserRole::Agent,
    ));

    /** @var TenantInvitation $invitation */
    $invitation = TenantInvitation::query()->firstOrFail();

    expect($invitation->email)->toBe('invited@example.com')
        ->and($invitation->role)->toBe(UserRole::Agent)
        ->and($invitation->status)->toBe(InvitationStatus::Pending)
        ->and($invitation->invited_by)->toBe($owner->id)
        ->and($invitation->token_hash)->toBe(hash('sha256', $token))
        ->and($invitation->token_hash)->not->toBe($token)
        ->and($invitation->expires_at->isFuture())->toBeTrue()
        ->and($invitation->expires_at->gt(now()->addDays(6)))->toBeTrue();

    Notification::assertSentOnDemand(InvitationNotification::class, function (InvitationNotification $notification) use ($token): bool {
        return $notification->getToken() === $token;
    });

    // El token plano NO se persiste jamás.
    expect(DB::table('tenant_invitations')->pluck('token_hash')->all())->toHaveCount(1);
});

test('INV-10: aceptar con el email correcto materializa la membresía activa y el rol spatie', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $invited = User::factory()->create(['email' => 'invited@example.com']);

    $token = invitation_token(fn () => app(InvitationService::class)->invite(
        $owner,
        $tenant,
        'invited@example.com',
        UserRole::Agent,
    ));

    $this->actingAs($invited)
        ->postJson('/api/v1/invitations/'.$token.'/accept')
        ->assertOk()
        ->assertJsonPath('role', 'agent')
        ->assertJsonPath('tenant_id', $tenant->id);

    $membership = TenantUser::query()
        ->where('tenant_id', $tenant->id)
        ->where('user_id', $invited->id)
        ->firstOrFail();

    expect($membership->role)->toBe(UserRole::Agent)
        ->and($membership->status->value)->toBe('active')
        ->and($membership->joined_at)->not->toBeNull();

    $this->assertDatabaseHas('tenant_invitations', [
        'tenant_id' => $tenant->id,
        'email' => 'invited@example.com',
        'status' => 'accepted',
    ]);

    $this->assertDatabaseHas('model_has_roles', [
        'model_id' => $invited->id,
        'model_type' => $invited->getMorphClass(),
        'tenant_id' => $tenant->id,
    ]);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'user.invitation_accepted',
        'actor_user_id' => $invited->id,
        'tenant_id' => $tenant->id,
    ]);
});

test('INV-11: aceptar con un email distinto es 403 y no crea membresía', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $stranger = User::factory()->create(['email' => 'other@example.com']);

    $token = invitation_token(fn () => app(InvitationService::class)->invite(
        $owner,
        $tenant,
        'invited@example.com',
        UserRole::Agent,
    ));

    $this->actingAs($stranger)
        ->postJson('/api/v1/invitations/'.$token.'/accept')
        ->assertStatus(403)
        ->assertJson(['code' => 'INVITATION_EMAIL_MISMATCH']);

    $this->assertDatabaseMissing('tenant_users', [
        'tenant_id' => $tenant->id,
        'user_id' => $stranger->id,
    ]);

    $this->assertDatabaseHas('tenant_invitations', [
        'tenant_id' => $tenant->id,
        'email' => 'invited@example.com',
        'status' => 'pending',
    ]);
});

test('INV-12: el token no es reutilizable tras aceptar (409)', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $invited = User::factory()->create(['email' => 'invited@example.com']);

    $token = invitation_token(fn () => app(InvitationService::class)->invite(
        $owner,
        $tenant,
        'invited@example.com',
        UserRole::Agent,
    ));

    $this->actingAs($invited)
        ->postJson('/api/v1/invitations/'.$token.'/accept')
        ->assertOk();

    $this->actingAs($invited)
        ->postJson('/api/v1/invitations/'.$token.'/accept')
        ->assertStatus(409)
        ->assertJson(['code' => 'INVITATION_ALREADY_ACCEPTED']);

    $this->actingAs($invited)
        ->getJson('/api/v1/invitations/'.$token)
        ->assertStatus(409)
        ->assertJson(['code' => 'INVITATION_ALREADY_ACCEPTED']);
});

test('INV-13: una invitación revocada no se puede consultar ni aceptar (410)', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $invited = User::factory()->create(['email' => 'invited@example.com']);

    $token = invitation_token(fn () => app(InvitationService::class)->invite(
        $owner,
        $tenant,
        'invited@example.com',
        UserRole::Agent,
    ));

    $invitation = TenantInvitation::query()->firstOrFail();
    app(InvitationService::class)->revoke($owner, $tenant, $invitation);

    $this->getJson('/api/v1/invitations/'.$token)
        ->assertStatus(410)
        ->assertJson(['code' => 'INVITATION_REVOKED']);

    $this->actingAs($invited)
        ->postJson('/api/v1/invitations/'.$token.'/accept')
        ->assertStatus(410)
        ->assertJson(['code' => 'INVITATION_REVOKED']);
});

test('INV-14: una invitación expirada se marca y no es aceptable (410)', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $invited = User::factory()->create(['email' => 'invited@example.com']);

    $token = invitation_token(fn () => app(InvitationService::class)->invite(
        $owner,
        $tenant,
        'invited@example.com',
        UserRole::Agent,
    ));

    $invitation = TenantInvitation::query()->firstOrFail();
    $invitation->forceFill(['expires_at' => now()->subDay()])->save();

    $this->getJson('/api/v1/invitations/'.$token)
        ->assertStatus(410)
        ->assertJson(['code' => 'INVITATION_EXPIRED']);

    $this->assertDatabaseHas('tenant_invitations', [
        'tenant_id' => $tenant->id,
        'status' => 'expired',
    ]);

    $this->actingAs($invited)
        ->postJson('/api/v1/invitations/'.$token.'/accept')
        ->assertStatus(410)
        ->assertJson(['code' => 'INVITATION_EXPIRED']);
});
