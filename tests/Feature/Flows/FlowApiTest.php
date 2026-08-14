<?php

declare(strict_types=1);

use App\Domain\Flows\Enums\FlowExecutionStatus;
use App\Domain\Flows\Enums\FlowStatus;
use App\Domain\Flows\Models\Chatbot;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| FASE 11 — API de chatbots, flujos, triggers y ejecuciones
|--------------------------------------------------------------------------
*/

function chatbot_url(Tenant $tenant, ?string $chatbotId = null): string
{
    $base = '/api/v1/tenants/'.$tenant->id.'/chatbots';

    return $chatbotId === null ? $base : $base.'/'.$chatbotId;
}

function flows_url(Tenant $tenant, string $chatbotId, ?string $flowId = null, ?string $suffix = null): string
{
    $base = '/api/v1/tenants/'.$tenant->id.'/chatbots/'.$chatbotId.'/flows';

    if ($flowId !== null) {
        $base .= '/'.$flowId;
    }

    return $suffix === null ? $base : $base.$suffix;
}

function triggers_url(Tenant $tenant, string $flowId, ?string $triggerId = null): string
{
    $base = '/api/v1/tenants/'.$tenant->id.'/flows/'.$flowId.'/triggers';

    return $triggerId === null ? $base : $base.'/'.$triggerId;
}

function executions_url(Tenant $tenant, ?string $executionId = null, ?string $action = null): string
{
    $base = '/api/v1/tenants/'.$tenant->id.'/flow-executions';

    if ($executionId !== null) {
        $base .= '/'.$executionId;
    }

    return $action === null ? $base : $base.'/'.$action;
}

function flow_payload(): array
{
    $start = (string) Str::uuid();
    $end = (string) Str::uuid();

    return [
        'nodes' => [
            ['id' => $start, 'type' => 'message', 'name' => 'Saludo', 'config' => ['text' => 'Hola'], 'is_start' => true],
            ['id' => $end, 'type' => 'end', 'name' => 'Fin', 'config' => []],
        ],
        'connections' => [
            ['source_node_id' => $start, 'target_node_id' => $end, 'label' => null],
        ],
    ];
}

test('FLOW-16: CRUD de chatbots con la matriz de permisos', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $this->actingAs($owner)
        ->postJson(chatbot_url($tenant), ['name' => 'Atención', 'description' => 'Básico'])
        ->assertStatus(201)
        ->assertJsonPath('chatbot.name', 'Atención');

    $chatbot = Chatbot::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();

    $this->actingAs($owner)
        ->getJson(chatbot_url($tenant).'?search=Aten')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('chatbots.0.id', $chatbot->id)
        ->assertJsonPath('chatbots.0.flows_count', 0);

    $this->actingAs($owner)
        ->getJson(chatbot_url($tenant, $chatbot->id))
        ->assertOk()
        ->assertJsonPath('chatbot.id', $chatbot->id);

    $this->actingAs($owner)
        ->patchJson(chatbot_url($tenant, $chatbot->id), ['name' => 'Atención v2'])
        ->assertOk()
        ->assertJsonPath('chatbot.name', 'Atención v2');

    $this->actingAs($owner)
        ->deleteJson(chatbot_url($tenant, $chatbot->id))
        ->assertOk();

    $this->assertSoftDeleted('chatbots', ['id' => $chatbot->id]);
});

test('FLOW-17: index y show de flujos con nodos/conexiones/triggers', function (): void {
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

    make_trigger($flow, ['type' => 'start']);

    $this->actingAs($owner)
        ->getJson(flows_url($tenant, $chatbot->id))
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('flows.0.id', $flow->id)
        ->assertJsonPath('flows.0.status', 'draft');

    $this->actingAs($owner)
        ->getJson(flows_url($tenant, $chatbot->id).'?status=published')
        ->assertOk()
        ->assertJsonPath('meta.total', 0);

    $this->actingAs($owner)
        ->getJson('/api/v1/tenants/'.$tenant->id.'/flows/'.$flow->id)
        ->assertOk()
        ->assertJsonPath('flow.id', $flow->id)
        ->assertJsonCount(2, 'flow.nodes')
        ->assertJsonCount(1, 'flow.connections')
        ->assertJsonCount(1, 'flow.triggers')
        ->assertJsonPath('flow.nodes.0.is_start', true);
});

test('FLOW-18: replaceDraft persiste el grafo de forma atómica y valida la forma', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    $payload = flow_payload();

    $this->actingAs($owner)
        ->putJson('/api/v1/tenants/'.$tenant->id.'/flows/'.$flow->id.'/draft', $payload)
        ->assertOk()
        ->assertJsonPath('flow.name', $flow->name)
        ->assertJsonCount(2, 'flow.nodes');

    $this->assertDatabaseHas('flow_nodes', ['flow_id' => $flow->id, 'type' => 'message', 'is_start' => true]);
    $this->assertDatabaseHas('flow_connections', ['flow_id' => $flow->id]);

    // La forma del payload se valida (422 de validación de Laravel).
    $this->actingAs($owner)
        ->putJson('/api/v1/tenants/'.$tenant->id.'/flows/'.$flow->id.'/draft', ['nodes' => [], 'connections' => []])
        ->assertStatus(422);

    $this->actingAs($owner)
        ->putJson('/api/v1/tenants/'.$tenant->id.'/flows/'.$flow->id.'/draft', [
            'nodes' => [['id' => (string) Str::uuid(), 'type' => 'tipo-invalido']],
            'connections' => [],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('nodes.0.type');
});

test('FLOW-19: replaceDraft rechaza un grafo inválido (sin nodo de inicio) con 422 FLOW_INVALID', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    $nodeId = (string) Str::uuid();

    $this->actingAs($owner)
        ->putJson('/api/v1/tenants/'.$tenant->id.'/flows/'.$flow->id.'/draft', [
            'nodes' => [['id' => $nodeId, 'type' => 'message', 'name' => 'Solo', 'config' => ['text' => 'Hola']]],
            'connections' => [],
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'FLOW_INVALID');
});

test('FLOW-20: publicar valida el grafo, deactivar exige published y el estado se audita', function (): void {
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

    // Publicar un flujo inválido (sin triggers no es inválido; usamos un grafo
    // sin start node) → 422 FLOW_INVALID.
    $bad = make_flow($tenant, $chatbot);
    $badNode = (string) Str::uuid();
    make_flow_graph($bad, [
        ['id' => $badNode, 'type' => 'message', 'name' => 'Solo', 'config' => ['text' => 'Hola']],
    ], []);

    $this->actingAs($owner)
        ->postJson('/api/v1/tenants/'.$tenant->id.'/flows/'.$bad->id.'/publish')
        ->assertStatus(422)
        ->assertJsonPath('code', 'FLOW_INVALID');

    // Publicar el válido → published + audit.
    $this->actingAs($owner)
        ->postJson('/api/v1/tenants/'.$tenant->id.'/flows/'.$flow->id.'/publish')
        ->assertOk()
        ->assertJsonPath('flow.status', 'published');

    $this->assertDatabaseHas('flows', ['id' => $flow->id, 'status' => 'published']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'flow.published', 'subject_id' => $flow->id]);

    // Republish → 409 FLOW_ALREADY_PUBLISHED.
    $this->actingAs($owner)
        ->postJson('/api/v1/tenants/'.$tenant->id.'/flows/'.$flow->id.'/publish')
        ->assertStatus(409)
        ->assertJsonPath('code', 'FLOW_ALREADY_PUBLISHED');

    // Deactivar un flujo no publicado → 409 FLOW_INVALID_STATE.
    $this->actingAs($owner)
        ->postJson('/api/v1/tenants/'.$tenant->id.'/flows/'.$bad->id.'/deactivate')
        ->assertStatus(409)
        ->assertJsonPath('code', 'FLOW_INVALID_STATE');

    // Deactivar el publicado → inactive.
    $this->actingAs($owner)
        ->postJson('/api/v1/tenants/'.$tenant->id.'/flows/'.$flow->id.'/deactivate')
        ->assertOk()
        ->assertJsonPath('flow.status', 'inactive');

    $this->assertDatabaseHas('flows', ['id' => $flow->id, 'status' => 'inactive']);
});

test('FLOW-21: un flujo publicado NO se edita ni se elimina (409 FLOW_PUBLISHED)', function (): void {
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

    $flow->forceFill(['status' => FlowStatus::Published->value])->save();

    $this->actingAs($owner)
        ->patchJson('/api/v1/tenants/'.$tenant->id.'/flows/'.$flow->id, ['name' => 'Rename'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'FLOW_PUBLISHED');

    $this->actingAs($owner)
        ->putJson('/api/v1/tenants/'.$tenant->id.'/flows/'.$flow->id.'/draft', flow_payload())
        ->assertStatus(409)
        ->assertJsonPath('code', 'FLOW_PUBLISHED');

    $this->actingAs($owner)
        ->deleteJson('/api/v1/tenants/'.$tenant->id.'/flows/'.$flow->id)
        ->assertStatus(409)
        ->assertJsonPath('code', 'FLOW_PUBLISHED');

    $this->assertDatabaseHas('flows', ['id' => $flow->id, 'name' => $flow->name]);
});

test('FLOW-22: CRUD de triggers (solo en flujos no publicados)', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    $this->actingAs($owner)
        ->postJson(triggers_url($tenant, $flow->id), ['type' => 'keyword', 'keyword' => 'oferta'])
        ->assertStatus(201)
        ->assertJsonPath('trigger.type', 'keyword')
        ->assertJsonPath('trigger.keyword', 'oferta')
        ->assertJsonPath('trigger.active', true);

    $trigger = TenantContext::withId($tenant->id, fn () => $flow->triggers()->firstOrFail());

    $this->actingAs($owner)
        ->getJson(triggers_url($tenant, $flow->id))
        ->assertOk()
        ->assertJsonCount(1, 'triggers');

    $this->actingAs($owner)
        ->patchJson(triggers_url($tenant, $flow->id, $trigger->id), ['active' => false])
        ->assertOk()
        ->assertJsonPath('trigger.active', false);

    $this->actingAs($owner)
        ->deleteJson(triggers_url($tenant, $flow->id, $trigger->id))
        ->assertOk();

    $this->assertDatabaseMissing('triggers', ['id' => $trigger->id]);

    // keyword requerido para type=keyword.
    $this->actingAs($owner)
        ->postJson(triggers_url($tenant, $flow->id), ['type' => 'keyword'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('keyword');

    // Flujo publicado → 409 FLOW_PUBLISHED.
    $flow->forceFill(['status' => FlowStatus::Published->value])->save();

    $this->actingAs($owner)
        ->postJson(triggers_url($tenant, $flow->id), ['type' => 'start'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'FLOW_PUBLISHED');
});

test('FLOW-23: el validador de un flujo responde {valid, errors} y la API lo expone', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $chatbot = make_chatbot($tenant);
    $valid = make_flow($tenant, $chatbot);
    make_flow_graph($valid, [
        ['id' => 'n1', 'type' => 'message', 'name' => 'Saludo', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
    ]);

    $this->actingAs($owner)
        ->getJson('/api/v1/tenants/'.$tenant->id.'/flows/'.$valid->id.'/validate')
        ->assertOk()
        ->assertJsonPath('valid', true)
        ->assertJsonCount(0, 'errors');

    $bad = make_flow($tenant, $chatbot);
    $badNode = (string) Str::uuid();
    make_flow_graph($bad, [
        ['id' => $badNode, 'type' => 'message', 'name' => 'Solo', 'config' => ['text' => 'Hola']],
    ], []);

    $this->actingAs($owner)
        ->getJson('/api/v1/tenants/'.$tenant->id.'/flows/'.$bad->id.'/validate')
        ->assertOk()
        ->assertJsonPath('valid', false)
        ->assertJsonCount(1, 'errors');
});

test('FLOW-24: CRITICO — aislamiento A/B: el tenant B jamás ve/edita recursos del tenant A', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $ownerB = User::factory()->create();
    make_tenant_member($ownerB, $tenantB, 'owner');

    $chatbot = make_chatbot($tenantA);
    $flow = make_flow($tenantA, $chatbot);
    $execution = make_flow_execution($tenantA, $flow);

    // Chatbots de A invisibles para B (404, no 403).
    $this->actingAs($ownerB)
        ->getJson(chatbot_url($tenantA))
        ->assertStatus(404);

    $this->actingAs($ownerB)
        ->getJson(chatbot_url($tenantA, $chatbot->id))
        ->assertStatus(404);

    $this->actingAs($ownerB)
        ->patchJson(chatbot_url($tenantA, $chatbot->id), ['name' => 'X'])
        ->assertStatus(404);

    // Flujos de A invisibles para B.
    $this->actingAs($ownerB)
        ->getJson(flows_url($tenantA, $chatbot->id))
        ->assertStatus(404);

    $this->actingAs($ownerB)
        ->getJson('/api/v1/tenants/'.$tenantA->id.'/flows/'.$flow->id)
        ->assertStatus(404);

    $this->actingAs($ownerB)
        ->putJson('/api/v1/tenants/'.$tenantA->id.'/flows/'.$flow->id.'/draft', flow_payload())
        ->assertStatus(404);

    // Ejecuciones de A invisibles para B.
    $this->actingAs($ownerB)
        ->getJson(executions_url($tenantA))
        ->assertStatus(404);

    $this->actingAs($ownerB)
        ->getJson(executions_url($tenantA, $execution->id))
        ->assertStatus(404);

    $this->actingAs($ownerB)
        ->postJson(executions_url($tenantA, $execution->id, 'cancel'))
        ->assertStatus(404);

    // Y los datos de A siguen intactos.
    $this->assertDatabaseHas('chatbots', ['id' => $chatbot->id, 'tenant_id' => $tenantA->id]);
    $this->assertDatabaseHas('flows', ['id' => $flow->id, 'tenant_id' => $tenantA->id]);
    $this->assertDatabaseHas('flow_executions', ['id' => $execution->id, 'tenant_id' => $tenantA->id]);
});

test('FLOW-25: la matriz de permisos se aplica en la API (agent solo lee)', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    $agent = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');
    make_tenant_member($agent, $tenant, 'agent');

    $chatbot = make_chatbot($tenant);

    // El agente puede LEER (flows.view a todos los roles).
    $this->actingAs($agent)
        ->getJson(chatbot_url($tenant))
        ->assertOk();

    // El agente NO puede MUTAR (flows.manage solo owner/admin).
    $this->actingAs($agent)
        ->postJson(chatbot_url($tenant), ['name' => 'Nuevo'])
        ->assertStatus(403)
        ->assertJsonPath('code', 'PERMISSION_DENIED');

    $this->actingAs($agent)
        ->postJson(flows_url($tenant, $chatbot->id), ['name' => 'Flujo'])
        ->assertStatus(403);

    // Ejecuciones: puede leer pero no pausar.
    $flow = make_flow($tenant, $chatbot);
    $execution = make_flow_execution($tenant, $flow);

    $this->actingAs($agent)
        ->getJson(executions_url($tenant))
        ->assertOk();

    $this->actingAs($agent)
        ->postJson(executions_url($tenant, $execution->id, 'pause'))
        ->assertStatus(403)
        ->assertJsonPath('code', 'PERMISSION_DENIED');
});

test('FLOW-26: index de ejecuciones con filtros y show', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $chatbot = make_chatbot($tenant);
    $flowA = make_flow($tenant, $chatbot);
    $flowB = make_flow($tenant, $chatbot);

    $execA = make_flow_execution($tenant, $flowA, ['status' => FlowExecutionStatus::Waiting->value]);
    $execB = make_flow_execution($tenant, $flowB, ['status' => FlowExecutionStatus::Completed->value]);

    $this->actingAs($owner)
        ->getJson(executions_url($tenant))
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonCount(2, 'executions');

    $this->actingAs($owner)
        ->getJson(executions_url($tenant).'?status=waiting')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('executions.0.id', $execA->id)
        ->assertJsonPath('executions.0.status', 'waiting');

    $this->actingAs($owner)
        ->getJson(executions_url($tenant).'?flow_id='.$flowA->id)
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('executions.0.id', $execA->id);

    $this->actingAs($owner)
        ->getJson(executions_url($tenant).'?chatbot_id='.$chatbot->id)
        ->assertOk()
        ->assertJsonPath('meta.total', 2);

    $this->actingAs($owner)
        ->getJson(executions_url($tenant, $execB->id))
        ->assertOk()
        ->assertJsonPath('execution.id', $execB->id)
        ->assertJsonPath('execution.flow.id', $flowB->id);
});

test('FLOW-27: pause/resume/cancel de una ejecución activa (y 409 sobre terminal)', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);
    $execution = make_flow_execution($tenant, $flow, ['status' => FlowExecutionStatus::Waiting->value]);

    $this->actingAs($owner)
        ->postJson(executions_url($tenant, $execution->id, 'pause'))
        ->assertOk()
        ->assertJsonPath('execution.status', 'waiting');

    $this->assertDatabaseHas('conversations', [
        'id' => $execution->conversation_id,
        'bot_paused' => true,
    ]);

    $this->actingAs($owner)
        ->postJson(executions_url($tenant, $execution->id, 'resume'))
        ->assertOk();

    $this->assertDatabaseHas('conversations', [
        'id' => $execution->conversation_id,
        'bot_paused' => false,
    ]);

    // Operaciones sobre una ejecución terminal → 409 EXECUTION_INVALID_STATE.
    $terminal = make_flow_execution($tenant, $flow, ['status' => FlowExecutionStatus::Completed->value]);

    $this->actingAs($owner)
        ->postJson(executions_url($tenant, $terminal->id, 'pause'))
        ->assertStatus(409)
        ->assertJsonPath('code', 'EXECUTION_INVALID_STATE');

    $this->actingAs($owner)
        ->postJson(executions_url($tenant, $terminal->id, 'cancel'))
        ->assertStatus(409);

    // Cancelar una activa la marca completed y limpia el enlace.
    $this->actingAs($owner)
        ->postJson(executions_url($tenant, $execution->id, 'cancel'))
        ->assertOk()
        ->assertJsonPath('execution.status', 'completed');

    $this->assertDatabaseHas('flow_executions', ['id' => $execution->id, 'status' => 'completed']);
    $this->assertDatabaseHas('conversations', ['id' => $execution->conversation_id, 'flow_execution_id' => null]);
});

test('FLOW-28: las mutaciones de la FASE 11 quedan auditadas', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $this->actingAs($owner)
        ->postJson(chatbot_url($tenant), ['name' => 'Auditado'])
        ->assertStatus(201);

    $chatbot = Chatbot::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();

    $this->assertDatabaseHas('audit_logs', ['action' => 'flow.chatbot_created', 'subject_id' => $chatbot->id]);
});
