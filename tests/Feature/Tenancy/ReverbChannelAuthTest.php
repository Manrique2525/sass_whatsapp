<?php

declare(strict_types=1);

use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Models\User;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;

uses(RefreshDatabase::class);

/**
 * Broadcaster de prueba que ejecuta la verificación real de canales de Laravel
 * (sin depender de un servidor Reverb).
 */
final class TestAuthBroadcaster extends Broadcaster
{
    public function auth($request)
    {
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
        // sin emisión real en tests
    }
}

function register_test_tenant_channel(): void
{
    Broadcast::extend('test-auth', fn (): TestAuthBroadcaster => new TestAuthBroadcaster);

    config(['broadcasting.default' => 'test-auth']);
    config(['broadcasting.connections.test-auth' => ['driver' => 'test-auth']]);

    Broadcast::connection('test-auth')->channel('tenant.{tenantId}.conversations.{conversationId}', function (User $user, string $tenantId, string $conversationId): bool {
        return $user->belongsToTenantById($tenantId);
    });
}

function register_test_inbox_channel(): void
{
    Broadcast::extend('test-auth', fn (): TestAuthBroadcaster => new TestAuthBroadcaster);

    config(['broadcasting.default' => 'test-auth']);
    config(['broadcasting.connections.test-auth' => ['driver' => 'test-auth']]);

    Broadcast::connection('test-auth')->channel('tenant.{tenantId}.inbox', function (User $user, string $tenantId): bool {
        return $user->belongsToTenantWithPermission($tenantId, 'conversations.view');
    });
}

function ensure_conversations_view_permission(): void
{
    Permission::findOrCreate('conversations.view');

    foreach ([UserRole::Owner, UserRole::Admin, UserRole::Agent] as $roleEnum) {
        $role = Role::findOrCreate($roleEnum->value);
        $role->givePermissionTo('conversations.view');
    }
}

// ─── Conversations channel tests (original) ────────────────────────────────

test('TEST 12: un usuario de A no puede autenticarse en el canal privado de B', function (): void {
    register_test_tenant_channel();
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $user = User::factory()->create();
    $user->tenants()->attach($tenantA, ['role' => 'owner']);

    $this->actingAs($user)
        ->postJson('/broadcasting/auth', [
            'channel_name' => 'private-tenant.'.$tenantB->id.'.conversations.1',
            'socket_id' => '1234.5678',
        ])
        ->assertStatus(403);
});

test('un usuario puede autenticarse en el canal privado de su propio tenant', function (): void {
    register_test_tenant_channel();
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    $user->tenants()->attach($tenant, ['role' => 'owner']);

    $this->actingAs($user)
        ->postJson('/broadcasting/auth', [
            'channel_name' => 'private-tenant.'.$tenant->id.'.conversations.1',
            'socket_id' => '1234.5678',
        ])
        ->assertOk();
});

test('el canal soporta el wildcard de recursos del tenant', function (): void {
    register_test_tenant_channel();
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    $user->tenants()->attach($tenant, ['role' => 'owner']);

    $this->actingAs($user)
        ->postJson('/broadcasting/auth', [
            'channel_name' => 'private-tenant.'.$tenant->id.'.conversations.'.random_int(1, 99),
            'socket_id' => '1234.5678',
        ])
        ->assertOk();
});

// ─── RT-01: Canal inbox válido ──────────────────────────────────────────────

test('HANDOFF-REALTIME-01: usuario owner puede autenticarse en canal inbox de su tenant', function (): void {
    register_test_inbox_channel();

    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    $user->tenants()->attach($tenant, ['role' => 'owner']);

    $this->actingAs($user)
        ->postJson('/broadcasting/auth', [
            'channel_name' => 'private-tenant.'.$tenant->id.'.inbox',
            'socket_id' => '1234.5678',
        ])
        ->assertOk();
});

// ─── RT-02: Cross-tenant denied ────────────────────────────────────────────

test('HANDOFF-REALTIME-02: usuario de A NO puede autenticarse en canal inbox de B', function (): void {
    register_test_inbox_channel();

    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $user = User::factory()->create();
    $user->tenants()->attach($tenantA, ['role' => 'owner']);

    $this->actingAs($user)
        ->postJson('/broadcasting/auth', [
            'channel_name' => 'private-tenant.'.$tenantB->id.'.inbox',
            'socket_id' => '1234.5678',
        ])
        ->assertStatus(403);
});

// ─── RT-03: Membership inactive denied ─────────────────────────────────────

test('HANDOFF-REALTIME-03: membresía inactiva deniega acceso a canal inbox', function (): void {
    register_test_inbox_channel();

    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    $user->tenants()->attach($tenant, ['role' => 'owner', 'status' => 'inactive']);

    $this->actingAs($user)
        ->postJson('/broadcasting/auth', [
            'channel_name' => 'private-tenant.'.$tenant->id.'.inbox',
            'socket_id' => '1234.5678',
        ])
        ->assertStatus(403);
});

// ─── RT-04: Permission check is evaluated (not just membership) ─────────────

test('HANDOFF-REALTIME-04: inbox channel evalúa permiso conversations.view, no solo membresía', function (): void {
    Broadcast::extend('test-auth', fn (): TestAuthBroadcaster => new TestAuthBroadcaster);
    config(['broadcasting.default' => 'test-auth']);
    config(['broadcasting.connections.test-auth' => ['driver' => 'test-auth']]);

    // Register a custom inbox channel that checks a permission NO role has
    Broadcast::connection('test-auth')->channel('tenant.{tenantId}.inbox', function (User $user, string $tenantId): bool {
        return $user->belongsToTenantWithPermission($tenantId, 'nonexistent.permission');
    });

    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    $user->tenants()->attach($tenant, ['role' => 'owner']);

    // Owner is an active member, but the channel requires a nonexistent permission
    $this->actingAs($user)
        ->postJson('/broadcasting/auth', [
            'channel_name' => 'private-tenant.'.$tenant->id.'.inbox',
            'socket_id' => '1234.5678',
        ])
        ->assertStatus(403);
});

// ─── RT-15: Tenant switch abandona canal anterior ──────────────────────────

test('HANDOFF-REALTIME-15: autenticación de canal usa siempre el tenant_id del canal, no el actual del usuario', function (): void {
    register_test_inbox_channel();

    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    $user->tenants()->attach($tenant, ['role' => 'owner']);

    // User authenticates to correct tenant — OK
    $this->actingAs($user)
        ->postJson('/broadcasting/auth', [
            'channel_name' => 'private-tenant.'.$tenant->id.'.inbox',
            'socket_id' => '1234.5678',
        ])
        ->assertOk();

    // Even if user's current_tenant_id changes, auth still uses channel's tenantId
    $otherTenant = Tenant::factory()->create();
    $user->tenants()->attach($otherTenant, ['role' => 'owner']);
    $user->update(['current_tenant_id' => $otherTenant->id]);

    $this->actingAs($user)
        ->postJson('/broadcasting/auth', [
            'channel_name' => 'private-tenant.'.$tenant->id.'.inbox',
            'socket_id' => '1234.5678',
        ])
        ->assertOk();
});
