<?php

declare(strict_types=1);

use App\Domain\Flows\Enums\FlowStatus;
use App\Domain\Flows\Enums\FlowTriggerType;
use App\Domain\Flows\Models\Chatbot;
use App\Domain\Flows\Models\Flow;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| FASE 14 UNIDAD 1 — publicación de flujos con triggers (ADR-038/039)
|--------------------------------------------------------------------------
*/

function trigger_publish_route(Tenant $tenant, string $flowId): string
{
    return '/api/v1/tenants/'.$tenant->id.'/flows/'.$flowId.'/publish';
}

function trigger_publish_flow(Tenant $tenant, Chatbot $chatbot): Flow
{
    $flow = make_flow($tenant, $chatbot);
    $n1 = (string) Str::uuid();
    $n2 = (string) Str::uuid();
    make_flow_graph($flow, [
        ['id' => $n1, 'type' => 'message', 'name' => 'Saludo', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => $n2, 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => $n1, 'to' => $n2],
    ]);

    return $flow;
}

test('U1-P01: CRITICO — a lo sumo un flujo publicado por tenant puede tener un trigger genérico activo del mismo tipo (409)', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $chatbot = make_chatbot($tenant);

    $flowA = trigger_publish_flow($tenant, $chatbot);
    make_trigger($flowA, ['type' => FlowTriggerType::NewMessage->value]);

    $this->actingAs($owner)
        ->postJson(trigger_publish_route($tenant, $flowA->id))
        ->assertOk()
        ->assertJsonPath('flow.status', 'published');

    $flowB = trigger_publish_flow($tenant, $chatbot);
    make_trigger($flowB, ['type' => FlowTriggerType::NewMessage->value]);

    $this->actingAs($owner)
        ->postJson(trigger_publish_route($tenant, $flowB->id))
        ->assertStatus(409)
        ->assertJsonPath('code', 'FLOW_ALREADY_PUBLISHED');

    $this->assertDatabaseHas('flows', ['id' => $flowB->id, 'status' => 'draft']);
});

test('U1-P02: genéricos de distinto tipo (new_message vs start) pueden coexistir publicados', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $chatbot = make_chatbot($tenant);

    $flowA = trigger_publish_flow($tenant, $chatbot);
    make_trigger($flowA, ['type' => FlowTriggerType::NewMessage->value]);

    $this->actingAs($owner)->postJson(trigger_publish_route($tenant, $flowA->id))->assertOk();

    $flowB = trigger_publish_flow($tenant, $chatbot);
    make_trigger($flowB, ['type' => FlowTriggerType::Start->value]);

    $this->actingAs($owner)
        ->postJson(trigger_publish_route($tenant, $flowB->id))
        ->assertOk()
        ->assertJsonPath('flow.status', 'published');
});

test('U1-P03: los triggers específicos (keyword) pueden coexistir incluso con la misma palabra', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $chatbot = make_chatbot($tenant);

    $flowA = trigger_publish_flow($tenant, $chatbot);
    make_trigger($flowA, ['type' => FlowTriggerType::Keyword->value, 'keyword' => 'oferta']);

    $this->actingAs($owner)->postJson(trigger_publish_route($tenant, $flowA->id))->assertOk();

    $flowB = trigger_publish_flow($tenant, $chatbot);
    make_trigger($flowB, ['type' => FlowTriggerType::Keyword->value, 'keyword' => 'oferta']);

    $this->actingAs($owner)
        ->postJson(trigger_publish_route($tenant, $flowB->id))
        ->assertOk();
});

test('U1-P04: un trigger genérico inactivo no bloquea la publicación de otro flujo', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $chatbot = make_chatbot($tenant);

    $flowA = trigger_publish_flow($tenant, $chatbot);
    make_trigger($flowA, ['type' => FlowTriggerType::NewMessage->value, 'active' => false]);

    $this->actingAs($owner)->postJson(trigger_publish_route($tenant, $flowA->id))->assertOk();

    $flowB = trigger_publish_flow($tenant, $chatbot);
    make_trigger($flowB, ['type' => FlowTriggerType::NewMessage->value]);

    $this->actingAs($owner)
        ->postJson(trigger_publish_route($tenant, $flowB->id))
        ->assertOk();
});

test('U1-P05: deactivar el flujo publicado libera el trigger genérico', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $chatbot = make_chatbot($tenant);

    $flowA = trigger_publish_flow($tenant, $chatbot);
    make_trigger($flowA, ['type' => FlowTriggerType::NewMessage->value]);

    $this->actingAs($owner)->postJson(trigger_publish_route($tenant, $flowA->id))->assertOk();

    $this->actingAs($owner)
        ->postJson('/api/v1/tenants/'.$tenant->id.'/flows/'.$flowA->id.'/deactivate')
        ->assertOk();

    $flowB = trigger_publish_flow($tenant, $chatbot);
    make_trigger($flowB, ['type' => FlowTriggerType::NewMessage->value]);

    $this->actingAs($owner)
        ->postJson(trigger_publish_route($tenant, $flowB->id))
        ->assertOk();
});

test('U1-P06: un flujo sin triggers y otro con genéricos conviven sin conflictos', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $chatbot = make_chatbot($tenant);

    $flowA = trigger_publish_flow($tenant, $chatbot);
    $this->actingAs($owner)->postJson(trigger_publish_route($tenant, $flowA->id))->assertOk();

    $flowB = trigger_publish_flow($tenant, $chatbot);
    make_trigger($flowB, ['type' => FlowTriggerType::NewMessage->value]);

    $this->actingAs($owner)
        ->postJson(trigger_publish_route($tenant, $flowB->id))
        ->assertOk();
});

test('U1-P07: CRITICO — el conflicto genérico es por tenant: el tenant B publica sin verse afectado por A', function (): void {
    $tenantA = Tenant::factory()->create();
    $ownerA = User::factory()->create();
    make_tenant_member($ownerA, $tenantA, 'owner');

    $chatbotA = make_chatbot($tenantA);
    $flowA = trigger_publish_flow($tenantA, $chatbotA);
    make_trigger($flowA, ['type' => FlowTriggerType::NewMessage->value]);

    $this->actingAs($ownerA)->postJson(trigger_publish_route($tenantA, $flowA->id))->assertOk();

    $tenantB = Tenant::factory()->create();
    $ownerB = User::factory()->create();
    make_tenant_member($ownerB, $tenantB, 'owner');

    $chatbotB = make_chatbot($tenantB);
    $flowB = trigger_publish_flow($tenantB, $chatbotB);
    make_trigger($flowB, ['type' => FlowTriggerType::NewMessage->value]);

    $this->actingAs($ownerB)
        ->postJson(trigger_publish_route($tenantB, $flowB->id))
        ->assertOk()
        ->assertJsonPath('flow.status', 'published');
});

test('U1-P08: publicar valida también la config de los triggers (keyword vacío → 422 FLOW_INVALID)', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $chatbot = make_chatbot($tenant);
    $flow = trigger_publish_flow($tenant, $chatbot);
    make_trigger($flow, ['type' => FlowTriggerType::Keyword->value, 'keyword' => '']);

    $this->actingAs($owner)
        ->postJson(trigger_publish_route($tenant, $flow->id))
        ->assertStatus(422)
        ->assertJsonPath('code', 'FLOW_INVALID');

    $this->assertDatabaseHas('flows', ['id' => $flow->id, 'status' => 'draft']);
});

test('U1-P09: un trigger schedule con config inválida bloquea la publicación (422)', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $chatbot = make_chatbot($tenant);
    $flow = trigger_publish_flow($tenant, $chatbot);
    make_trigger($flow, ['type' => FlowTriggerType::Schedule->value, 'config' => ['cron' => '99 * * * *']]);

    $this->actingAs($owner)
        ->postJson(trigger_publish_route($tenant, $flow->id))
        ->assertStatus(422)
        ->assertJsonPath('code', 'FLOW_INVALID');
});

test('U1-P10: los flujos publicados existentes no se ven afectados por la nueva regla', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $chatbot = make_chatbot($tenant);

    // Flujo con start publicado ANTES de la regla de publicación (estado forzado).
    $flowA = trigger_publish_flow($tenant, $chatbot);
    make_trigger($flowA, ['type' => FlowTriggerType::Start->value]);
    $flowA->forceFill(['status' => FlowStatus::Published->value])->save();

    // Publicar otro flujo con start genérico → debe seguir bloqueándose.
    $flowB = trigger_publish_flow($tenant, $chatbot);
    make_trigger($flowB, ['type' => FlowTriggerType::Start->value]);

    $this->actingAs($owner)
        ->postJson(trigger_publish_route($tenant, $flowB->id))
        ->assertStatus(409)
        ->assertJsonPath('code', 'FLOW_ALREADY_PUBLISHED');
});
