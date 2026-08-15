<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Flows\Models\Trigger;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| FASE 14 UNIDAD 1 — API de triggers: validación de config, token de webhook
| y referencias seguras dentro del tenant
|--------------------------------------------------------------------------
*/

function tenant_trigger_route(Tenant $tenant, string $flowId, ?string $triggerId = null): string
{
    $base = '/api/v1/tenants/'.$tenant->id.'/flows/'.$flowId.'/triggers';

    return $triggerId === null ? $base : $base.'/'.$triggerId;
}

function trigger_setup(array $options = []): array
{
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    return ['tenant' => $tenant, 'owner' => $owner, 'flow' => $flow];
}

function stored_trigger_config(Tenant $tenant, string $triggerId): array
{
    return TenantContext::withId($tenant->id, fn () => (array) Trigger::query()->findOrFail($triggerId)->config);
}

test('U1-A01: crear un webhook devuelve el token en claro una única vez y el recurso lo redacta', function (): void {
    ['tenant' => $tenant, 'owner' => $owner, 'flow' => $flow] = trigger_setup();

    $response = $this->actingAs($owner)
        ->postJson(tenant_trigger_route($tenant, $flow->id), [
            'type' => 'webhook',
            'config' => ['conversation_by' => 'phone'],
        ])
        ->assertStatus(201)
        ->assertJsonPath('trigger.type', 'webhook')
        ->assertJsonPath('trigger.config', ['conversation_by' => 'phone']);

    $token = $response->json('webhook_token');
    $triggerId = $response->json('trigger.id');

    expect($token)->toBeString()
        ->and(strlen($token))->toBe(64)
        ->and(preg_match('/^[a-f0-9]{64}$/', $token))->toBe(1);

    // La config persistida guarda SOLO el hash.
    $stored = stored_trigger_config($tenant, $triggerId);
    expect($stored['conversation_by'])->toBe('phone')
        ->and(preg_match('/^[a-f0-9]{64}$/', (string) $stored['token_hash']))->toBe(1)
        ->and($stored['token_hash'])->not->toBe($token);
});

test('U1-A02: el token webhook nunca reaparece en index/show ni en auditoría', function (): void {
    ['tenant' => $tenant, 'owner' => $owner, 'flow' => $flow] = trigger_setup();

    $response = $this->actingAs($owner)
        ->postJson(tenant_trigger_route($tenant, $flow->id), [
            'type' => 'webhook',
            'config' => ['conversation_by' => 'phone'],
        ])
        ->assertStatus(201);

    $token = $response->json('webhook_token');
    $triggerId = $response->json('trigger.id');

    $this->actingAs($owner)
        ->getJson(tenant_trigger_route($tenant, $flow->id))
        ->assertOk()
        ->assertJsonCount(1, 'triggers')
        ->assertJsonMissing(['triggers.0.config.token_hash' => null])
        ->assertDontSee($token);

    $log = AuditLog::query()
        ->where('action', 'flow.trigger_created')
        ->where('subject_id', $triggerId)
        ->firstOrFail();

    expect(json_encode($log->data))->not->toContain('token_hash')->not->toContain($token);
});

test('U1-A03: el cliente jamás provee token o token_hash en la config del webhook', function (): void {
    ['tenant' => $tenant, 'owner' => $owner, 'flow' => $flow] = trigger_setup();

    $this->actingAs($owner)
        ->postJson(tenant_trigger_route($tenant, $flow->id), [
            'type' => 'webhook',
            'config' => ['conversation_by' => 'phone', 'token_hash' => str_repeat('a', 64)],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('config');

    $this->actingAs($owner)
        ->postJson(tenant_trigger_route($tenant, $flow->id), [
            'type' => 'webhook',
            'config' => ['conversation_by' => 'phone', 'token' => 'secreto'],
        ])
        ->assertStatus(422);
});

test('U1-A04: webhook inválido (conversation_by ausente o incorrecto) → 422', function (): void {
    ['tenant' => $tenant, 'owner' => $owner, 'flow' => $flow] = trigger_setup();

    foreach ([
        ['type' => 'webhook'],
        ['type' => 'webhook', 'config' => []],
        ['type' => 'webhook', 'config' => ['conversation_by' => 'celular']],
        ['type' => 'webhook', 'config' => ['conversation_by' => 'phone', 'otro' => 1]],
    ] as $payload) {
        $this->actingAs($owner)
            ->postJson(tenant_trigger_route($tenant, $flow->id), $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('config');
    }
});

test('U1-A05: schedule válido exige cron determinista y conversación del tenant', function (): void {
    ['tenant' => $tenant, 'owner' => $owner, 'flow' => $flow] = trigger_setup();

    $conversation = make_conversation($tenant, make_contact($tenant));

    $this->actingAs($owner)
        ->postJson(tenant_trigger_route($tenant, $flow->id), [
            'type' => 'schedule',
            'config' => ['cron' => '0 9 * * 1-5', 'conversation_id' => $conversation->id],
        ])
        ->assertStatus(201)
        ->assertJsonPath('trigger.type', 'schedule')
        ->assertJsonPath('trigger.config', ['cron' => '0 9 * * 1-5', 'conversation_id' => $conversation->id]);
});

test('U1-A06: schedule con cron inválido o sin conversation_id → 422', function (): void {
    ['tenant' => $tenant, 'owner' => $owner, 'flow' => $flow] = trigger_setup();

    $conversation = make_conversation($tenant, make_contact($tenant));

    $this->actingAs($owner)
        ->postJson(tenant_trigger_route($tenant, $flow->id), [
            'type' => 'schedule',
            'config' => ['cron' => '99 * * * *', 'conversation_id' => $conversation->id],
        ])
        ->assertStatus(422);

    $this->actingAs($owner)
        ->postJson(tenant_trigger_route($tenant, $flow->id), [
            'type' => 'schedule',
            'config' => ['cron' => '0 9 * * 1-5'],
        ])
        ->assertStatus(422);
});

test('U1-A07: CRITICO — el schedule jamás acepta una conversación de otro tenant (404)', function (): void {
    ['tenant' => $tenant, 'owner' => $owner, 'flow' => $flow] = trigger_setup();

    $otherTenant = Tenant::factory()->create();
    $otherConversation = make_conversation($otherTenant, make_contact($otherTenant));

    $this->actingAs($owner)
        ->postJson(tenant_trigger_route($tenant, $flow->id), [
            'type' => 'schedule',
            'config' => ['cron' => '0 9 * * 1-5', 'conversation_id' => $otherConversation->id],
        ])
        ->assertStatus(404);

    $this->actingAs($owner)
        ->postJson(tenant_trigger_route($tenant, $flow->id), [
            'type' => 'schedule',
            'config' => ['cron' => '0 9 * * 1-5', 'conversation_id' => (string) Str::uuid()],
        ])
        ->assertStatus(404);
});

test('U1-A08: tag valida la config sin ejecutar nada (FASE 20) y configura el contrato', function (): void {
    ['tenant' => $tenant, 'owner' => $owner, 'flow' => $flow] = trigger_setup();

    $this->actingAs($owner)
        ->postJson(tenant_trigger_route($tenant, $flow->id), [
            'type' => 'tag',
            'config' => ['tags' => ['vip', 'nuevo']],
        ])
        ->assertStatus(201)
        ->assertJsonPath('trigger.config', ['tags' => ['vip', 'nuevo']]);

    $this->actingAs($owner)
        ->postJson(tenant_trigger_route($tenant, $flow->id), [
            'type' => 'tag',
            'config' => ['tags' => []],
        ])
        ->assertStatus(422);

    $this->actingAs($owner)
        ->postJson(tenant_trigger_route($tenant, $flow->id), [
            'type' => 'tag',
            'config' => ['tags' => ['vip', 'vip']],
        ])
        ->assertStatus(422);
});

test('U1-A09: los triggers de mensaje no admiten config arbitraria', function (): void {
    ['tenant' => $tenant, 'owner' => $owner, 'flow' => $flow] = trigger_setup();

    $this->actingAs($owner)
        ->postJson(tenant_trigger_route($tenant, $flow->id), [
            'type' => 'keyword',
            'keyword' => 'oferta',
            'config' => ['extra' => true],
        ])
        ->assertStatus(422);

    $this->actingAs($owner)
        ->postJson(tenant_trigger_route($tenant, $flow->id), [
            'type' => 'new_message',
            'config' => ['extra' => true],
        ])
        ->assertStatus(422);

    $this->actingAs($owner)
        ->postJson(tenant_trigger_route($tenant, $flow->id), [
            'type' => 'start',
            'config' => ['extra' => true],
        ])
        ->assertStatus(422);
});

test('U1-A10: actualizar un webhook preserva su token_hash y lo sigue redactando', function (): void {
    ['tenant' => $tenant, 'owner' => $owner, 'flow' => $flow] = trigger_setup();

    $created = $this->actingAs($owner)
        ->postJson(tenant_trigger_route($tenant, $flow->id), [
            'type' => 'webhook',
            'config' => ['conversation_by' => 'phone'],
        ])
        ->assertStatus(201);

    $triggerId = $created->json('trigger.id');
    $originalHash = stored_trigger_config($tenant, $triggerId)['token_hash'];

    $this->actingAs($owner)
        ->patchJson(tenant_trigger_route($tenant, $flow->id, $triggerId), [
            'config' => ['conversation_by' => 'contact_id'],
        ])
        ->assertOk()
        ->assertJsonPath('trigger.config', ['conversation_by' => 'contact_id']);

    $stored = stored_trigger_config($tenant, $triggerId);
    expect($stored['conversation_by'])->toBe('contact_id')
        ->and($stored['token_hash'])->toBe($originalHash);

    $this->actingAs($owner)
        ->getJson(tenant_trigger_route($tenant, $flow->id))
        ->assertOk()
        ->assertDontSee($originalHash);
});

test('U1-A11: actualizar con config inválida (cambiar webhook a keyword sin palabra) → 422', function (): void {
    ['tenant' => $tenant, 'owner' => $owner, 'flow' => $flow] = trigger_setup();

    $created = $this->actingAs($owner)
        ->postJson(tenant_trigger_route($tenant, $flow->id), [
            'type' => 'webhook',
            'config' => ['conversation_by' => 'phone'],
        ])
        ->assertStatus(201);

    $this->actingAs($owner)
        ->patchJson(tenant_trigger_route($tenant, $flow->id, $created->json('trigger.id')), [
            'type' => 'keyword',
            'config' => null,
        ])
        ->assertStatus(422);
});

test('U1-A12: el tenant B jamás lee ni referencia los triggers del tenant A', function (): void {
    ['tenant' => $tenant, 'owner' => $owner, 'flow' => $flow] = trigger_setup();

    $created = $this->actingAs($owner)
        ->postJson(tenant_trigger_route($tenant, $flow->id), [
            'type' => 'webhook',
            'config' => ['conversation_by' => 'phone'],
        ])
        ->assertStatus(201);

    $tenantB = Tenant::factory()->create();
    $ownerB = User::factory()->create();
    make_tenant_member($ownerB, $tenantB, 'owner');

    $this->actingAs($ownerB)
        ->getJson(tenant_trigger_route($tenant, $flow->id))
        ->assertStatus(404);

    $this->actingAs($ownerB)
        ->patchJson(tenant_trigger_route($tenant, $flow->id, $created->json('trigger.id')), ['active' => false])
        ->assertStatus(404);
});
