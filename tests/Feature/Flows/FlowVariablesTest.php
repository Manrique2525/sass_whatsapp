<?php

declare(strict_types=1);

use App\Domain\Conversations\Models\Conversation;
use App\Domain\Flows\Enums\FlowStatus;
use App\Domain\Flows\Enums\FlowTriggerType;
use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Models\FlowExecution;
use App\Domain\Flows\Services\VariableGuard;
use App\Domain\Tenants\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| FASE 13 — UNIDAD 1: normalización de la captura de variables (VAR-12)
|--------------------------------------------------------------------------
*/

/**
 * Publica un flujo Inicio → question (field dado) → mensaje → Fin, con
 * trigger Start. Se fuerza el estado `published` directamente (sin pasar por
 * FlowValidator) para poder probar la defensa en profundidad del motor con
 * datos corruptos (p. ej. field en mayúsculas).
 */
function publish_variables_flow(Tenant $tenant, string $field): Flow
{
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    make_flow_graph($flow, [
        ['id' => 'n1', 'type' => 'message', 'name' => 'Inicio', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'question', 'name' => 'Pregunta', 'config' => ['prompt' => '¿Dato?', 'field' => $field]],
        ['id' => 'n3', 'type' => 'message', 'name' => 'Gracias', 'config' => ['text' => 'Gracias']],
        ['id' => 'n4', 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
        ['from' => 'n2', 'to' => 'n3'],
        ['from' => 'n3', 'to' => 'n4'],
    ]);

    $flow->forceFill(['status' => FlowStatus::Published->value])->save();

    make_trigger($flow, ['type' => FlowTriggerType::Start->value]);

    return $flow;
}

test('VAR-12: la captura recorta los espacios del valor', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    publish_variables_flow($tenant, 'nombre');

    $first = make_inbound_message($tenant, 'Hola');
    $conversation = Conversation::query()->withoutTenantScope()->whereKey($first->conversation_id)->firstOrFail();
    run_flow_engine($tenant, $first, $conversation);

    $answer = make_inbound_message($tenant, '  Juan  ');
    $conversation->refresh();
    run_flow_engine($tenant, $answer, $conversation);

    $execution = FlowExecution::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();

    expect($execution->variables['custom']['nombre'])->toBe('Juan');
});

test('VAR-12: la captura normaliza el field a minúsculas (fix C8, defensa en profundidad)', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    publish_variables_flow($tenant, 'Nombre');

    $first = make_inbound_message($tenant, 'Hola');
    $conversation = Conversation::query()->withoutTenantScope()->whereKey($first->conversation_id)->firstOrFail();
    run_flow_engine($tenant, $first, $conversation);

    $answer = make_inbound_message($tenant, 'Ana');
    $conversation->refresh();
    run_flow_engine($tenant, $answer, $conversation);

    $execution = FlowExecution::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();

    expect($execution->variables['custom']['nombre'])->toBe('Ana')
        ->and($execution->variables['custom']['Nombre'] ?? null)->toBeNull();
});

test('VAR-12: la captura trunca valores largos a MAX_VALUE_LENGTH', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    publish_variables_flow($tenant, 'nota');

    $first = make_inbound_message($tenant, 'Hola');
    $conversation = Conversation::query()->withoutTenantScope()->whereKey($first->conversation_id)->firstOrFail();
    run_flow_engine($tenant, $first, $conversation);

    $answer = make_inbound_message($tenant, str_repeat('x', VariableGuard::MAX_VALUE_LENGTH + 100));
    $conversation->refresh();
    run_flow_engine($tenant, $answer, $conversation);

    $execution = FlowExecution::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();

    expect(mb_strlen((string) $execution->variables['custom']['nota']))->toBe(VariableGuard::MAX_VALUE_LENGTH);
});

test('VAR-12: una clave de field peligrosa en datos corruptos se descarta sin romper el flujo', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    publish_variables_flow($tenant, '__proto__');

    $first = make_inbound_message($tenant, 'Hola');
    $conversation = Conversation::query()->withoutTenantScope()->whereKey($first->conversation_id)->firstOrFail();
    run_flow_engine($tenant, $first, $conversation);

    $answer = make_inbound_message($tenant, 'x');
    $conversation->refresh();
    run_flow_engine($tenant, $answer, $conversation);

    $execution = FlowExecution::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();

    expect($execution->variables['custom'] ?? [])->toBe([]);
});
