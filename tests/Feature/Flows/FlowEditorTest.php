<?php

declare(strict_types=1);

use App\Domain\Flows\Enums\FlowStatus;
use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Models\FlowConnection;
use App\Domain\Flows\Models\FlowNode;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| FASE 12 — Flow Builder (editor visual) + lock optimista + webhook seguro
|--------------------------------------------------------------------------
*/

function editor_flow_payload(bool $withBase = false, ?string $base = null): array
{
    $start = (string) Str::uuid();
    $end = (string) Str::uuid();

    $payload = [
        'nodes' => [
            ['id' => $start, 'type' => 'message', 'name' => 'Saludo', 'config' => ['text' => 'Hola'], 'is_start' => true],
            ['id' => $end, 'type' => 'end', 'name' => 'Fin', 'config' => []],
        ],
        'connections' => [
            ['source_node_id' => $start, 'target_node_id' => $end, 'label' => null],
        ],
    ];

    if ($withBase) {
        $payload['base_updated_at'] = $base;
    }

    return $payload;
}

test('FLOW-29: los secretos del nodo webhook (headers/payload) se preservan al guardar y jamás se exponen por API', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    $webhook = (string) Str::uuid();
    $start = (string) Str::uuid();
    $end = (string) Str::uuid();

    make_flow_graph($flow, [
        ['id' => $start, 'type' => 'message', 'name' => 'Saludo', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => $webhook, 'type' => 'webhook', 'name' => 'Notificar', 'config' => [
            'url' => 'https://example.com/hook',
            'method' => 'POST',
            'headers' => ['Authorization' => 'Bearer top-secret'],
            'payload' => '{"from":"{{contact.name}}"}',
        ]],
        ['id' => $end, 'type' => 'end', 'name' => 'Fin', 'config' => []],
    ], [
        ['from' => $start, 'to' => $webhook],
        ['from' => $webhook, 'to' => $end],
    ]);

    // El editor reenvía el grafo SIN headers/payload (la UI solo muestra method+url).
    $this->actingAs($owner)
        ->putJson('/api/v1/tenants/'.$tenant->id.'/flows/'.$flow->id.'/draft', [
            'nodes' => [
                ['id' => $start, 'type' => 'message', 'name' => 'Saludo', 'config' => ['text' => 'Hola'], 'is_start' => true],
                ['id' => $webhook, 'type' => 'webhook', 'name' => 'Notificar', 'config' => ['url' => 'https://example.com/hook', 'method' => 'POST']],
                ['id' => $end, 'type' => 'end', 'name' => 'Fin', 'config' => []],
            ],
            'connections' => [
                ['source_node_id' => $start, 'target_node_id' => $webhook, 'label' => null],
                ['source_node_id' => $webhook, 'target_node_id' => $end, 'label' => null],
            ],
        ])
        ->assertOk();

    $this->assertDatabaseHas('flow_nodes', ['flow_id' => $flow->id, 'id' => $webhook]);
    $persisted = FlowNode::query()->withoutTenantScope()->where('flow_id', $flow->id)->whereKey($webhook)->firstOrFail();
    expect($persisted->config['headers'])->toBe(['Authorization' => 'Bearer top-secret'])
        ->and($persisted->config['payload'])->toBe('{"from":"{{contact.name}}"}');

    // Si el editor envía headers/payload explícitos, ganan (permite editarlos sin exponerlos).
    $this->actingAs($owner)
        ->putJson('/api/v1/tenants/'.$tenant->id.'/flows/'.$flow->id.'/draft', [
            'nodes' => [
                ['id' => $start, 'type' => 'message', 'name' => 'Saludo', 'config' => ['text' => 'Hola'], 'is_start' => true],
                ['id' => $webhook, 'type' => 'webhook', 'name' => 'Notificar', 'config' => [
                    'url' => 'https://example.com/hook',
                    'method' => 'POST',
                    'headers' => ['X-Key' => 'nuevo-secreto'],
                ]],
                ['id' => $end, 'type' => 'end', 'name' => 'Fin', 'config' => []],
            ],
            'connections' => [
                ['source_node_id' => $start, 'target_node_id' => $webhook, 'label' => null],
                ['source_node_id' => $webhook, 'target_node_id' => $end, 'label' => null],
            ],
        ])
        ->assertOk();

    $persisted = $persisted->fresh();
    expect($persisted->config['headers'])->toBe(['X-Key' => 'nuevo-secreto'])
        ->and($persisted->config['payload'])->toBe('{"from":"{{contact.name}}"}');

    // La API jamás expone headers/payload (solo method+url).
    $this->actingAs($owner)
        ->getJson('/api/v1/tenants/'.$tenant->id.'/flows/'.$flow->id)
        ->assertOk()
        ->assertJsonPath('flow.nodes.1.type', 'webhook')
        ->assertJsonMissingPath('flow.nodes.1.config.headers')
        ->assertJsonMissingPath('flow.nodes.1.config.payload');
});

test('FLOW-30: el lock optimista acepta base_updated_at actual, rechaza versiones obsoletas y es opcional', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    $base = $flow->fresh()->updated_at->toIso8601String();

    // base_updated_at ausente → 200 (compatibilidad con clientes sin editor).
    $this->actingAs($owner)
        ->putJson('/api/v1/tenants/'.$tenant->id.'/flows/'.$flow->id.'/draft', editor_flow_payload())
        ->assertOk();

    // base_updated_at igual a la versión actual → 200 y updated_at se renueva.
    $fresh = $flow->fresh();
    $this->actingAs($owner)
        ->putJson('/api/v1/tenants/'.$tenant->id.'/flows/'.$flow->id.'/draft', editor_flow_payload(withBase: true, base: $fresh->updated_at->toIso8601String()))
        ->assertOk();

    $touchedAt = $fresh->fresh()->updated_at;
    $this->actingAs($owner)
        ->getJson('/api/v1/tenants/'.$tenant->id.'/flows/'.$flow->id)
        ->assertOk()
        ->assertJsonPath('flow.status', 'draft');

    expect(Carbon::parse(Flow::query()->withoutTenantScope()->whereKey($flow->id)->value('updated_at'))->equalTo($touchedAt))->toBeTrue();

    // base_updated_at obsoleto (otro usuario tocó el flujo después) → 409 FLOW_CONFLICT.
    Flow::query()->withoutTenantScope()->whereKey($flow->id)->update(['updated_at' => now()->addSeconds(5)]);

    $this->actingAs($owner)
        ->putJson('/api/v1/tenants/'.$tenant->id.'/flows/'.$flow->id.'/draft', editor_flow_payload(withBase: true, base: $fresh->updated_at->toIso8601String()))
        ->assertStatus(409)
        ->assertJsonPath('code', 'FLOW_CONFLICT');

    // El conflicto NO muta el grafo ni el updated_at.
    expect(Flow::query()->withoutTenantScope()->whereKey($flow->id)->value('updated_at'))
        ->not->toBeNull();
});

test('FLOW-31: la página web del editor (wrapper Inertia) se renderiza para el tenant', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    $this->actingAs($owner)
        ->get('/settings/flows/'.$chatbot->id.'/'.$flow->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Flows/Editor')
            ->where('chatbotId', $chatbot->id)
            ->where('flowId', $flow->id));

    // Un usuario sin tenant activo no accede al editor.
    $outsider = User::factory()->create();
    $this->actingAs($outsider)
        ->get('/settings/flows/'.$chatbot->id.'/'.$flow->id)
        ->assertForbidden();
});

test('FLOW-32: el editor carga el flujo completo (nodos, conexiones, triggers, estado)', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot, ['status' => FlowStatus::Draft->value]);

    make_flow_graph($flow, [
        ['id' => 'n1', 'type' => 'message', 'name' => 'Saludo', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
    ]);

    $this->actingAs($owner)
        ->getJson('/api/v1/tenants/'.$tenant->id.'/flows/'.$flow->id)
        ->assertOk()
        ->assertJsonPath('flow.id', $flow->id)
        ->assertJsonPath('flow.status', 'draft')
        ->assertJsonCount(2, 'flow.nodes')
        ->assertJsonCount(1, 'flow.connections')
        ->assertJsonPath('flow.nodes.0.is_start', true)
        ->assertJsonPath('flow.nodes.0.position_x', 0);
});

test('FLOW-33/34: replaceDraft persiste un grafo actualizado (nodos + conexiones) de forma atómica', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    make_flow_graph($flow, [
        ['id' => 'n1', 'type' => 'message', 'name' => 'Viejo', 'config' => ['text' => 'Antes'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
    ]);

    $a = (string) Str::uuid();
    $b = (string) Str::uuid();
    $c = (string) Str::uuid();

    $this->actingAs($owner)
        ->putJson('/api/v1/tenants/'.$tenant->id.'/flows/'.$flow->id.'/draft', [
            'nodes' => [
                ['id' => $a, 'type' => 'buttons', 'name' => 'Opciones', 'config' => [
                    'text' => 'Elegí',
                    'buttons' => [
                        ['id' => 'a', 'title' => 'Sí'],
                        ['id' => 'b', 'title' => 'No'],
                    ],
                ], 'is_start' => true, 'position_x' => 12, 'position_y' => 34],
                ['id' => $b, 'type' => 'question', 'name' => 'Pregunta', 'config' => ['text' => '¿Nombre?', 'prompt' => '¿Cómo te llamás?', 'field' => 'nombre']],
                ['id' => $c, 'type' => 'end', 'name' => 'Fin', 'config' => []],
            ],
            'connections' => [
                ['source_node_id' => $a, 'target_node_id' => $b, 'label' => null],
                ['source_node_id' => $b, 'target_node_id' => $c, 'label' => null],
            ],
        ])
        ->assertOk()
        ->assertJsonCount(3, 'flow.nodes')
        ->assertJsonCount(2, 'flow.connections');

    expect(FlowNode::query()->withoutTenantScope()->where('flow_id', $flow->id)->count())->toBe(3)
        ->and(FlowConnection::query()->withoutTenantScope()->where('flow_id', $flow->id)->count())->toBe(2);

    $this->assertDatabaseHas('flow_nodes', ['flow_id' => $flow->id, 'id' => $a, 'position_x' => 12, 'position_y' => 34, 'is_start' => true]);
    $this->assertDatabaseMissing('flow_nodes', ['flow_id' => $flow->id, 'id' => 'n1']);

    // Un grafo inválido NO persiste nada (transacción atómica).
    $this->actingAs($owner)
        ->putJson('/api/v1/tenants/'.$tenant->id.'/flows/'.$flow->id.'/draft', [
            'nodes' => [['id' => (string) Str::uuid(), 'type' => 'message', 'name' => 'Sin start']],
            'connections' => [],
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'FLOW_INVALID');

    expect(FlowNode::query()->withoutTenantScope()->where('flow_id', $flow->id)->count())->toBe(3);
});

test('FLOW-35/36: solo el borrador se edita; publicar/editar publicado exige estados válidos', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    make_flow_graph($flow, [
        ['id' => 'n1', 'type' => 'message', 'name' => 'Saludo', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
    ]);

    $this->actingAs($owner)
        ->postJson('/api/v1/tenants/'.$tenant->id.'/flows/'.$flow->id.'/publish')
        ->assertOk()
        ->assertJsonPath('flow.status', 'published');

    // Publicar de nuevo → 409 FLOW_ALREADY_PUBLISHED.
    $this->actingAs($owner)
        ->postJson('/api/v1/tenants/'.$tenant->id.'/flows/'.$flow->id.'/publish')
        ->assertStatus(409)
        ->assertJsonPath('code', 'FLOW_ALREADY_PUBLISHED');

    // Editar un flujo publicado → 409 FLOW_PUBLISHED.
    $this->actingAs($owner)
        ->putJson('/api/v1/tenants/'.$tenant->id.'/flows/'.$flow->id.'/draft', editor_flow_payload())
        ->assertStatus(409)
        ->assertJsonPath('code', 'FLOW_PUBLISHED');

    $this->actingAs($owner)
        ->patchJson('/api/v1/tenants/'.$tenant->id.'/flows/'.$flow->id, ['name' => 'Renombrado'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'FLOW_PUBLISHED');

    // Deactivar (editable otra vez) y publicar de nuevo.
    $this->actingAs($owner)
        ->postJson('/api/v1/tenants/'.$tenant->id.'/flows/'.$flow->id.'/deactivate')
        ->assertOk()
        ->assertJsonPath('flow.status', 'inactive');

    $this->actingAs($owner)
        ->putJson('/api/v1/tenants/'.$tenant->id.'/flows/'.$flow->id.'/draft', editor_flow_payload())
        ->assertOk();
});

test('FLOW-37: el editor recibe FLOW_INVALID con los errores del validador', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    $nodeId = (string) Str::uuid();

    $this->actingAs($owner)
        ->putJson('/api/v1/tenants/'.$tenant->id.'/flows/'.$flow->id.'/draft', [
            'nodes' => [['id' => $nodeId, 'type' => 'message', 'name' => 'Sin start', 'config' => ['text' => 'Hola']]],
            'connections' => [],
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'FLOW_INVALID')
        ->assertJsonStructure(['errors' => []]);

    // El endpoint de validación es de solo lectura: devuelve {valid, errors}.
    $this->actingAs($owner)
        ->getJson('/api/v1/tenants/'.$tenant->id.'/flows/'.$flow->id.'/validate')
        ->assertOk()
        ->assertJsonPath('valid', false);
});

test('FLOW-38: un segundo guardado concurrente con la misma base recibe 409 FLOW_CONFLICT', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    $base = $flow->fresh()->updated_at->toIso8601String();

    // Primer editor guarda con base correcta.
    $this->actingAs($owner)
        ->putJson('/api/v1/tenants/'.$tenant->id.'/flows/'.$flow->id.'/draft', editor_flow_payload(withBase: true, base: $base))
        ->assertOk();

    // La escritura del primer editor queda commiteada con un timestamp posterior
    // (como ocurre en producción); el segundo editor guarda con la misma base
    // que cargó antes → conflicto.
    Flow::query()->withoutTenantScope()->whereKey($flow->id)->update(['updated_at' => now()->addSeconds(5)]);

    $this->actingAs($owner)
        ->putJson('/api/v1/tenants/'.$tenant->id.'/flows/'.$flow->id.'/draft', editor_flow_payload(withBase: true, base: $base))
        ->assertStatus(409)
        ->assertJsonPath('code', 'FLOW_CONFLICT');

    // La respuesta del conflicto expone la versión actual para poder recargar.
    $this->actingAs($owner)
        ->getJson('/api/v1/tenants/'.$tenant->id.'/flows/'.$flow->id)
        ->assertOk()
        ->assertJsonPath('flow.status', 'draft');
});

test('FLOW-39: el editor respeta el aislamiento A/B (404 cross-tenant)', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    make_tenant_member($ownerA, $tenantA, 'owner');
    make_tenant_member($ownerB, $tenantB, 'owner');

    $chatbotA = make_chatbot($tenantA);
    $flowA = make_flow($tenantA, $chatbotA);

    make_flow_graph($flowA, [
        ['id' => 'n1', 'type' => 'message', 'name' => 'Saludo', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
    ]);

    // B no ve el flujo de A.
    $this->actingAs($ownerB)
        ->getJson('/api/v1/tenants/'.$tenantA->id.'/flows/'.$flowA->id)
        ->assertNotFound();

    // B no puede editar el borrador de A.
    $this->actingAs($ownerB)
        ->putJson('/api/v1/tenants/'.$tenantA->id.'/flows/'.$flowA->id.'/draft', editor_flow_payload())
        ->assertNotFound();

    // A tampoco toca nada de B.
    $this->actingAs($ownerA)
        ->getJson('/api/v1/tenants/'.$tenantB->id.'/flows')
        ->assertNotFound();
});

test('FLOW-40: el tenant_id del payload nunca se confía (el backend lo fija)', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    $start = (string) Str::uuid();
    $end = (string) Str::uuid();

    $payload = editor_flow_payload();
    $payload['nodes'][0]['id'] = $start;
    $payload['nodes'][1]['id'] = $end;
    $payload['nodes'][0]['tenant_id'] = 'tenant-ajeno';
    $payload['connections'][0]['source_node_id'] = $start;
    $payload['connections'][0]['target_node_id'] = $end;

    $this->actingAs($owner)
        ->putJson('/api/v1/tenants/'.$tenant->id.'/flows/'.$flow->id.'/draft', $payload)
        ->assertOk();

    $persisted = FlowNode::query()->withoutTenantScope()->where('flow_id', $flow->id)->first();
    expect($persisted->tenant_id)->toBe($tenant->id);
});

test('FLOW-41: el editor aplica flows.view / flows.manage (agent lee, no edita)', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    $agent = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');
    make_tenant_member($agent, $tenant, 'agent');

    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    make_flow_graph($flow, [
        ['id' => 'n1', 'type' => 'message', 'name' => 'Saludo', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
    ]);

    // Agent lee el flujo.
    $this->actingAs($agent)
        ->getJson('/api/v1/tenants/'.$tenant->id.'/flows/'.$flow->id)
        ->assertOk()
        ->assertJsonPath('flow.id', $flow->id);

    // Agent no puede editar el borrador.
    $this->actingAs($agent)
        ->putJson('/api/v1/tenants/'.$tenant->id.'/flows/'.$flow->id.'/draft', editor_flow_payload())
        ->assertForbidden()
        ->assertJsonPath('code', 'PERMISSION_DENIED');

    // Owner sí.
    $this->actingAs($owner)
        ->putJson('/api/v1/tenants/'.$tenant->id.'/flows/'.$flow->id.'/draft', editor_flow_payload())
        ->assertOk();
});

test('FLOW-42: publicar tras una modificación guarda el grafo nuevo y valida', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    $start = (string) Str::uuid();
    $end = (string) Str::uuid();

    // Publicar un borrador vacío (sin nodos) → FLOW_INVALID (jamás se publica inválido).
    $this->actingAs($owner)
        ->postJson('/api/v1/tenants/'.$tenant->id.'/flows/'.$flow->id.'/publish')
        ->assertStatus(422)
        ->assertJsonPath('code', 'FLOW_INVALID');

    // Modificar el borrador y publicar.
    $this->actingAs($owner)
        ->putJson('/api/v1/tenants/'.$tenant->id.'/flows/'.$flow->id.'/draft', [
            'nodes' => [
                ['id' => $start, 'type' => 'message', 'name' => 'Saludo', 'config' => ['text' => 'Hola'], 'is_start' => true],
                ['id' => $end, 'type' => 'end', 'name' => 'Fin', 'config' => []],
            ],
            'connections' => [
                ['source_node_id' => $start, 'target_node_id' => $end, 'label' => null],
            ],
        ])
        ->assertOk();

    $this->actingAs($owner)
        ->postJson('/api/v1/tenants/'.$tenant->id.'/flows/'.$flow->id.'/publish')
        ->assertOk()
        ->assertJsonPath('flow.status', 'published');

    // El grafo recién guardado (con el id de cliente) quedó persistido.
    expect(FlowNode::query()->withoutTenantScope()->where('flow_id', $flow->id)->where('id', $start)->exists())->toBeTrue();
});

test('FLOW-43: dos editores concurrentes: solo el primero con la misma base persiste', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    $base = $flow->fresh()->updated_at->toIso8601String();

    $first = (string) Str::uuid();
    $second = (string) Str::uuid();

    // Editor A guarda su versión (base correcta).
    $this->actingAs($owner)
        ->putJson('/api/v1/tenants/'.$tenant->id.'/flows/'.$flow->id.'/draft', [
            'base_updated_at' => $base,
            'nodes' => [
                ['id' => $first, 'type' => 'message', 'name' => 'Versión A', 'config' => ['text' => 'A'], 'is_start' => true],
                ['id' => $second, 'type' => 'end', 'name' => 'Fin', 'config' => []],
            ],
            'connections' => [
                ['source_node_id' => $first, 'target_node_id' => $second, 'label' => null],
            ],
        ])
        ->assertOk();

    // El commit de A queda registrado con timestamp posterior (producción real);
    // B, que cargó la misma base antes, intenta sobrescribir con un grafo válido
    // → conflicto de escritura, la versión de A no se pierde.
    Flow::query()->withoutTenantScope()->whereKey($flow->id)->update(['updated_at' => now()->addSeconds(5)]);

    $bStart = (string) Str::uuid();
    $bEnd = (string) Str::uuid();

    $this->actingAs($owner)
        ->putJson('/api/v1/tenants/'.$tenant->id.'/flows/'.$flow->id.'/draft', [
            'base_updated_at' => $base,
            'nodes' => [
                ['id' => $bStart, 'type' => 'message', 'name' => 'Versión B', 'config' => ['text' => 'B'], 'is_start' => true],
                ['id' => $bEnd, 'type' => 'end', 'name' => 'Fin', 'config' => []],
            ],
            'connections' => [
                ['source_node_id' => $bStart, 'target_node_id' => $bEnd, 'label' => null],
            ],
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'FLOW_CONFLICT');

    // La versión de A sigue intacta.
    $this->assertDatabaseHas('flow_nodes', ['flow_id' => $flow->id, 'id' => $first, 'name' => 'Versión A']);
});
