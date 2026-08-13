<?php

declare(strict_types=1);

use App\Domain\Tenants\Models\Tenant;
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
