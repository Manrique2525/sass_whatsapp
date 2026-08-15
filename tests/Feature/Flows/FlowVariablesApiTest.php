<?php

declare(strict_types=1);

use App\Domain\Business\Models\BusinessProfile;
use App\Domain\Flows\Models\Flow;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

function variables_url(Tenant $tenant, string $flowId): string
{
    return '/api/v1/tenants/'.$tenant->id.'/flows/'.$flowId.'/variables';
}

/**
 * @return array<string, array<string, mixed>>
 */
function variables_indexed(TestResponse $response): array
{
    return collect($response->json('variables'))->keyBy('key')->all();
}

test('VAR-8: GET catálogo con flows.view → 200 y expone la definición completa', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);
    make_flow_graph($flow, [
        ['id' => 'n1', 'type' => 'message', 'name' => 'Inicio', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'question', 'name' => 'Plan', 'config' => [
            'prompt' => '¿Plan?',
            'field' => 'plan',
            'type' => 'string',
            'default' => 'gratis',
        ]],
        ['id' => 'n3', 'type' => 'question', 'name' => 'Edad', 'config' => [
            'prompt' => '¿Edad?',
            'field' => 'edad',
            'type' => 'integer',
        ]],
        ['id' => 'n4', 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
        ['from' => 'n2', 'to' => 'n3'],
        ['from' => 'n3', 'to' => 'n4'],
    ]);

    $response = $this->actingAs($owner)->getJson(variables_url($tenant, $flow->id));

    $response->assertOk()
        ->assertJsonStructure([
            'variables' => [[
                'key',
                'label',
                'namespace',
                'source',
                'type',
                'default',
                'writable',
            ]],
        ]);

    $vars = variables_indexed($response);

    expect($vars)->toHaveKey('contact.name')
        ->and($vars)->toHaveKey('contact.email')
        ->and($vars)->toHaveKey('contact.phone')
        ->and($vars)->toHaveKey('conversation.id')
        ->and($vars['contact.name']['namespace'])->toBe('contact')
        ->and($vars['contact.name']['writable'])->toBeFalse()
        ->and($vars['conversation.id']['writable'])->toBeFalse();

    expect($vars['custom.plan']['type'])->toBe('string')
        ->and($vars['custom.plan']['default'])->toBe('gratis')
        ->and($vars['custom.plan']['writable'])->toBeTrue()
        ->and($vars['custom.plan']['source'])->toBe('question:Plan')
        ->and($vars['custom.edad']['type'])->toBe('integer')
        ->and($vars['custom.edad']['writable'])->toBeTrue();
});

test('VAR-8: el catálogo contiene exactamente la whitelist de business.*', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    $response = $this->actingAs($owner)->getJson(variables_url($tenant, $flow->id));

    $response->assertOk();

    $business = collect($response->json('variables'))
        ->where('namespace', 'business')
        ->all();

    expect($business)->toHaveCount(count(BusinessProfile::PUBLIC_FIELDS))
        ->and(array_column($business, 'key'))
        ->toBe(array_map(fn (string $field): string => 'business.'.$field, BusinessProfile::PUBLIC_FIELDS))
        ->and(collect($business)->every(fn (array $v): bool => $v['writable'] === false && $v['type'] === 'string'))
        ->toBeTrue()
        ->and(collect($business)->pluck('key'))
        ->not->toContain('business.access_token')
        ->not->toContain('business.token')
        ->not->toContain('business.tenant_id');
});

test('VAR-9: usuario sin flows.view recibe 403 PERMISSION_DENIED', function (): void {
    $tenant = Tenant::factory()->create();
    $noPermission = User::factory()->create();
    make_tenant_member($noPermission, $tenant, 'super_admin');

    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    $this->actingAs($noPermission)
        ->getJson(variables_url($tenant, $flow->id))
        ->assertStatus(403)
        ->assertJsonPath('code', 'PERMISSION_DENIED');
});

test('VAR-10: un flujo inexistente o de otro tenant devuelve 404', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    $this->actingAs($owner)
        ->getJson(variables_url($tenant, (string) Str::uuid()))
        ->assertNotFound();

    $tenantB = Tenant::factory()->create();
    $chatbotB = make_chatbot($tenantB);
    $flowB = make_flow($tenantB, $chatbotB);

    $this->actingAs($owner)
        ->getJson(variables_url($tenant, $flowB->id))
        ->assertNotFound();
});

test('VAR-11 / IDOR: Tenant A nunca obtiene custom ni business de Tenant B', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $userA = User::factory()->create();
    make_tenant_member($userA, $tenantA, 'owner');

    $chatbotA = make_chatbot($tenantA);
    $flowA = make_flow($tenantA, $chatbotA);
    make_flow_graph($flowA, [
        ['id' => 'a1', 'type' => 'message', 'name' => 'Inicio', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => 'a2', 'type' => 'question', 'name' => 'Campo A', 'config' => ['prompt' => '?', 'field' => 'a']],
        ['id' => 'a3', 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => 'a1', 'to' => 'a2'],
        ['from' => 'a2', 'to' => 'a3'],
    ]);

    $chatbotB = make_chatbot($tenantB);
    $flowB = make_flow($tenantB, $chatbotB);
    make_flow_graph($flowB, [
        ['id' => 'b1', 'type' => 'message', 'name' => 'Inicio', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => 'b2', 'type' => 'question', 'name' => 'Campo B', 'config' => ['prompt' => '?', 'field' => 'b']],
        ['id' => 'b3', 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => 'b1', 'to' => 'b2'],
        ['from' => 'b2', 'to' => 'b3'],
    ]);

    // GET FLOW A con Tenant A → custom.a.
    $catalogA = variables_indexed($this->actingAs($userA)->getJson(variables_url($tenantA, $flowA->id)));
    expect($catalogA)->toHaveKey('custom.a')
        ->and($catalogA)->not->toHaveKey('custom.b');

    // GET FLOW B con Tenant A → 404 (nunca custom.b).
    $this->actingAs($userA)
        ->getJson(variables_url($tenantA, $flowB->id))
        ->assertNotFound();

    // El namespace business es la whitelist estática (nunca valores de B).
    $businessKeys = collect($catalogA)->where('namespace', 'business')->keys()->all();
    expect($businessKeys)
        ->toBe(array_map(fn (string $field): string => 'business.'.$field, BusinessProfile::PUBLIC_FIELDS))
        ->and($businessKeys)->not->toContain('business.access_token');
});

test('VAR-20: el agente (flows.view) consulta el catálogo pero no gana permisos de edición', function (): void {
    $tenant = Tenant::factory()->create();
    $agent = User::factory()->create();
    make_tenant_member($agent, $tenant, 'agent');

    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    $this->actingAs($agent)
        ->getJson(variables_url($tenant, $flow->id))
        ->assertOk()
        ->assertJsonPath('variables.0.key', 'contact.name');

    // Consultar el catálogo no concede flows.manage.
    $this->actingAs($agent)
        ->postJson('/api/v1/tenants/'.$tenant->id.'/flows/'.$flow->id.'/publish')
        ->assertStatus(403)
        ->assertJsonPath('code', 'PERMISSION_DENIED');
});

test('VAR-20b: el catálogo es de solo lectura (POST/PUT/PATCH/DELETE → 405)', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);
    $url = variables_url($tenant, $flow->id);

    $this->actingAs($owner)->postJson($url)->assertStatus(405);
    $this->actingAs($owner)->putJson($url)->assertStatus(405);
    $this->actingAs($owner)->patchJson($url)->assertStatus(405);
    $this->actingAs($owner)->deleteJson($url)->assertStatus(405);
});

test('VAR-11: claves custom duplicadas se colapsan y las peligrosas no aparecen', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);
    make_flow_graph($flow, [
        ['id' => 'n1', 'type' => 'message', 'name' => 'Inicio', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'question', 'name' => 'Uno', 'config' => ['prompt' => '?', 'field' => 'clave', 'type' => 'integer']],
        ['id' => 'n3', 'type' => 'question', 'name' => 'Dos', 'config' => ['prompt' => '?', 'field' => 'clave', 'type' => 'string']],
        ['id' => 'n4', 'type' => 'question', 'name' => 'Peligro', 'config' => ['prompt' => '?', 'field' => '__proto__']],
        ['id' => 'n5', 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
        ['from' => 'n2', 'to' => 'n3'],
        ['from' => 'n3', 'to' => 'n4'],
        ['from' => 'n4', 'to' => 'n5'],
    ]);

    $vars = variables_indexed($this->actingAs($owner)->getJson(variables_url($tenant, $flow->id)));

    expect($vars)->toHaveKey('custom.clave')
        ->and($vars['custom.clave']['type'])->toBe('integer')
        ->and($vars['custom.clave']['source'])->toBe('question:Uno')
        ->and($vars)->not->toHaveKey('custom.__proto__');
});

test('SECRETS: el response del catálogo jamás expone tenant_id, secretos ni config de nodos', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);
    make_flow_graph($flow, [
        ['id' => 'n1', 'type' => 'message', 'name' => 'Inicio', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'webhook', 'name' => 'Notify', 'config' => [
            'method' => 'POST',
            'url' => 'https://webhook-interno.example.com/hook',
            'headers' => ['Authorization' => 'Bearer token-super-secreto'],
            'payload' => '{"password":"valor-secreto","api_key":"key-ultra-secreta"}',
        ]],
        ['id' => 'n3', 'type' => 'question', 'name' => 'Plan', 'config' => ['prompt' => '?', 'field' => 'plan']],
        ['id' => 'n4', 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
        ['from' => 'n2', 'to' => 'n3'],
        ['from' => 'n3', 'to' => 'n4'],
    ]);

    $response = $this->actingAs($owner)->getJson(variables_url($tenant, $flow->id));

    $response->assertOk();

    $content = $response->getContent();

    expect($content)
        ->not->toContain($tenant->id)
        ->not->toContain('tenant_id')
        ->not->toContain('access_token')
        ->not->toContain('token-super-secreto')
        ->not->toContain('valor-secreto')
        ->not->toContain('key-ultra-secreta')
        ->not->toContain('Authorization')
        ->not->toContain('password')
        ->not->toContain('api_key')
        ->not->toContain('webhook-interno')
        ->not->toContain('/hook');
});
