<?php

declare(strict_types=1);

use App\Application\WhatsApp\Services\WhatsAppMessagingService;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Domain\WhatsApp\Exceptions\WhatsAppMessageFailedException;
use App\Domain\WhatsApp\Exceptions\WhatsAppNotConnectedException;
use App\Domain\WhatsApp\Models\MessageSendAttempt;
use App\Domain\WhatsApp\Models\WhatsAppAccount;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| FASE 6 — WHATSAPP CONEXIÓN Y ENVÍO
|--------------------------------------------------------------------------
*/

function wa_url(Tenant $tenant, string $action = ''): string
{
    $base = '/api/v1/tenants/'.$tenant->id.'/whatsapp';

    return $action === '' ? $base : $base.'/'.$action;
}

function connect_payload(): array
{
    return [
        'whatsapp_business_account_id' => 'waba-1',
        'phone_number_id' => 'phone-1',
        'phone_number' => '+15550000002',
        'display_name' => 'Negocio Central',
        'access_token' => 'token-secreto-del-tenant',
    ];
}

/**
 * Llama a WhatsAppMessagingService con el TenantContext activo, igual que un
 * request autorizado (el middleware `tenant` lo fija antes de ejecutar).
 */
function send_whatsapp_text(User $user, Tenant $tenant, string $to, string $text): mixed
{
    $service = app(WhatsAppMessagingService::class);

    TenantContext::setId($tenant->id);

    try {
        return $service->sendText($user, $tenant, $to, $text);
    } finally {
        TenantContext::clear();
    }
}

test('WHATSAPP-15: conectar valida contra Meta y persiste el token CIFRADO', function (): void {
    Http::fake([
        'graph.facebook.com/*/phone-1*' => Http::response([
            'id' => 'phone-1',
            'verified_name' => 'Negocio Central',
            'display_phone_number' => '+15550000002',
            'quality_rating' => 'GREEN',
            'status' => 'connected',
        ], 200),
        'graph.facebook.com/*' => Http::response(['success' => true], 200),
    ]);

    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $this->actingAs($owner)
        ->postJson(wa_url($tenant, 'connect'), connect_payload())
        ->assertOk()
        ->assertJsonPath('whatsapp_account.status', 'connected')
        ->assertJsonPath('whatsapp_account.whatsapp_business_account_id', 'waba-1')
        ->assertJsonPath('webhook_subscribed', true)
        ->assertJsonMissingPath('whatsapp_account.access_token');

    $this->assertDatabaseHas('whatsapp_accounts', [
        'tenant_id' => $tenant->id,
        'whatsapp_business_account_id' => 'waba-1',
        'status' => 'connected',
    ]);

    $this->assertDatabaseHas('whatsapp_phone_numbers', [
        'tenant_id' => $tenant->id,
        'phone_id' => 'phone-1',
        'verified_name' => 'Negocio Central',
        'status' => 'connected',
    ]);

    $account = WhatsAppAccount::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();

    // El token se guarda cifrado (no es texto plano) pero se descifra al leer.
    expect($account->getRawOriginal('access_token'))->not->toBe('token-secreto-del-tenant')
        ->and($account->access_token)->toBe('token-secreto-del-tenant');

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'whatsapp.connected',
        'tenant_id' => $tenant->id,
        'actor_user_id' => $owner->id,
    ]);
});

test('WHATSAPP-16: conectar con token inválido en Meta responde 401 WHATSAPP_AUTH_FAILED y no persiste', function (): void {
    Http::fake([
        'graph.facebook.com/*' => Http::response([
            'error' => [
                'message' => 'Invalid OAuth access token.',
                'type' => 'OAuthException',
                'code' => 190,
            ],
        ], 401),
    ]);

    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $this->actingAs($owner)
        ->postJson(wa_url($tenant, 'connect'), connect_payload())
        ->assertStatus(401)
        ->assertJson(['code' => 'WHATSAPP_AUTH_FAILED']);

    $this->assertDatabaseMissing('whatsapp_accounts', ['tenant_id' => $tenant->id]);
});

test('WHATSAPP-17: conectar con phone_id inexistente responde 404 WHATSAPP_PHONE_NOT_FOUND', function (): void {
    Http::fake([
        'graph.facebook.com/*' => Http::response([
            'error' => [
                'message' => 'Unsupported get request.',
                'code' => 100,
            ],
        ], 404),
    ]);

    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $this->actingAs($owner)
        ->postJson(wa_url($tenant, 'connect'), connect_payload())
        ->assertStatus(404)
        ->assertJson(['code' => 'WHATSAPP_PHONE_NOT_FOUND']);

    $this->assertDatabaseMissing('whatsapp_accounts', ['tenant_id' => $tenant->id]);
});

test('WHATSAPP-18: GET del estado lo permite cualquier rol del tenant', function (): void {
    $tenant = Tenant::factory()->create();
    $agent = User::factory()->create();
    make_tenant_member($agent, $tenant, 'agent');

    $this->actingAs($agent)
        ->getJson(wa_url($tenant))
        ->assertOk()
        ->assertJsonPath('whatsapp_account', null);
});

test('WHATSAPP-19: un agente NO puede conectar ni desconectar (403)', function (): void {
    $tenant = Tenant::factory()->create();
    $agent = User::factory()->create();
    make_tenant_member($agent, $tenant, 'agent');

    $this->actingAs($agent)
        ->postJson(wa_url($tenant, 'connect'), connect_payload())
        ->assertStatus(403)
        ->assertJson(['code' => 'PERMISSION_DENIED']);

    $this->actingAs($agent)
        ->postJson(wa_url($tenant, 'disconnect'))
        ->assertStatus(403)
        ->assertJson(['code' => 'PERMISSION_DENIED']);

    $this->assertDatabaseMissing('whatsapp_accounts', ['tenant_id' => $tenant->id]);
});

test('WHATSAPP-20: CRITICO — Tenant A jamás ve ni desconecta el WhatsApp de Tenant B', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    make_tenant_member($ownerA, $tenantA, 'owner');
    make_tenant_member($ownerB, $tenantB, 'owner');

    make_whatsapp_setup($tenantB);

    // A no ve el estado de B: 404.
    $this->actingAs($ownerA)
        ->getJson(wa_url($tenantB))
        ->assertStatus(404);

    // A no puede desconectar a B: 404.
    $this->actingAs($ownerA)
        ->postJson(wa_url($tenantB, 'disconnect'))
        ->assertStatus(404);

    // B sigue conectado.
    $this->assertDatabaseHas('whatsapp_accounts', [
        'tenant_id' => $tenantB->id,
        'status' => 'connected',
    ]);
});

test('WHATSAPP-21: desconectar anula el token, conserva el historial y audita', function (): void {
    Http::fake([
        'graph.facebook.com/*' => Http::response(['success' => true], 200),
    ]);

    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    make_whatsapp_setup($tenant);

    $this->actingAs($owner)
        ->postJson(wa_url($tenant, 'disconnect'))
        ->assertOk()
        ->assertJsonPath('whatsapp_account.status', 'disconnected')
        ->assertJsonMissingPath('whatsapp_account.access_token');

    $account = WhatsAppAccount::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();

    expect($account->access_token)->toBeNull()
        ->and($account->status->value)->toBe('disconnected');

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'whatsapp.disconnected',
        'tenant_id' => $tenant->id,
        'actor_user_id' => $owner->id,
    ]);
});

test('WHATSAPP-22: desconectar sin cuenta conectada responde 409 WHATSAPP_NOT_CONNECTED', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $this->actingAs($owner)
        ->postJson(wa_url($tenant, 'disconnect'))
        ->assertStatus(409)
        ->assertJson(['code' => 'WHATSAPP_NOT_CONNECTED']);
});

test('WHATSAPP-23: conectar sin cuenta en Meta falla con WHATSAPP_AUTH_FAILED (validación primero)', function (): void {
    Http::fake([
        'graph.facebook.com/*' => Http::response([
            'error' => ['message' => 'Invalid OAuth access token.', 'code' => 190],
        ], 401),
    ]);

    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $this->actingAs($owner)
        ->postJson(wa_url($tenant, 'connect'), connect_payload())
        ->assertStatus(401)
        ->assertJson(['code' => 'WHATSAPP_AUTH_FAILED']);
});

test('WHATSAPP-24: el payload de conexión valida campos requeridos', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $this->actingAs($owner)
        ->postJson(wa_url($tenant, 'connect'), [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['whatsapp_business_account_id', 'phone_number_id', 'access_token']);
});

test('WHATSAPP-25: el envío registra el intento, persiste provider_message_id y audita', function (): void {
    Http::fake([
        'graph.facebook.com/*/phone-1/messages' => Http::response([
            'messaging_product' => 'whatsapp',
            'contacts' => [['input' => '15550000001', 'wa_id' => '15550000001']],
            'messages' => [['id' => 'wamid-123']],
        ], 200),
    ]);

    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $setup = make_whatsapp_setup($tenant);
    $phone = $setup['phone'];

    $this->actingAs($owner);
    $result = send_whatsapp_text($owner, $tenant, '15550000001', 'Hola cliente');

    expect($result->providerMessageId)->toBe('wamid-123');

    $this->assertDatabaseHas('message_send_attempts', [
        'whatsapp_phone_number_id' => $phone->id,
        'to' => '15550000001',
        'status' => 'sent',
        'provider_message_id' => 'wamid-123',
        'attempt' => 1,
    ]);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'whatsapp.message_sent',
        'tenant_id' => $tenant->id,
        'actor_user_id' => $owner->id,
    ]);
});

test('WHATSAPP-26: un error permanente de Meta marca failed con retryable=false y 502', function (): void {
    Http::fake([
        'graph.facebook.com/*/phone-1/messages' => Http::response([
            'error' => [
                'message' => '(#131030) Recipient phone number not in allowed list.',
                'type' => 'OAuthException',
                'code' => 131030,
            ],
        ], 400),
    ]);

    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $setup = make_whatsapp_setup($tenant);
    $phone = $setup['phone'];

    $this->actingAs($owner);

    try {
        send_whatsapp_text($owner, $tenant, '15550000001', 'Hola cliente');
        $this->fail('Se esperaba WhatsAppMessageFailedException.');
    } catch (WhatsAppMessageFailedException $e) {
        expect($e->status())->toBe(502)
            ->and($e->errorCode()->value)->toBe('WHATSAPP_MESSAGE_FAILED')
            ->and($e->providerErrorCode())->toBe('131030')
            ->and($e->retryable())->toBeFalse();
    }

    $attempt = MessageSendAttempt::query()->withoutTenantScope()->where('whatsapp_phone_number_id', $phone->id)->firstOrFail();

    expect($attempt->status->value)->toBe('failed')
        ->and($attempt->error_code)->toBe('WHATSAPP_MESSAGE_FAILED')
        ->and($attempt->error_message)->toContain('131030');

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'whatsapp.message_failed',
        'tenant_id' => $tenant->id,
        'actor_user_id' => $owner->id,
    ]);
});

test('WHATSAPP-27: enviar sin cuenta conectada responde 409', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $this->expectException(WhatsAppNotConnectedException::class);
    send_whatsapp_text($owner, $tenant, '15550000001', 'Hola');
});

test('WHATSAPP-28: un error transitorio de Meta (5xx) es retryable', function (): void {
    Http::fake([
        'graph.facebook.com/*/phone-1/messages' => Http::response([
            'error' => ['message' => 'Upstream error.', 'code' => 2],
        ], 500),
    ]);

    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    make_whatsapp_setup($tenant);

    try {
        send_whatsapp_text($owner, $tenant, '15550000001', 'Hola');
        $this->fail('Se esperaba WhatsAppMessageFailedException.');
    } catch (WhatsAppMessageFailedException $e) {
        expect($e->retryable())->toBeTrue();
    }
});

test('WHATSAPP-29: la API nunca expone el access_token', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    make_whatsapp_setup($tenant, ['access_token' => 'token-secreto-del-tenant']);

    $response = $this->actingAs($owner)->getJson(wa_url($tenant));

    $response->assertOk();
    expect($response->getContent())->not->toContain('token-secreto-del-tenant')
        ->and($response->getContent())->not->toContain('access_token');
});

test('WHATSAPP-30: un no-miembro del tenant recibe 404', function (): void {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $stranger = User::factory()->create();
    make_tenant_member($stranger, $other, 'owner');

    $this->actingAs($stranger)
        ->getJson(wa_url($tenant))
        ->assertStatus(404);
});
