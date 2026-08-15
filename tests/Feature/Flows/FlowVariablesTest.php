<?php

declare(strict_types=1);

use App\Domain\Conversations\Models\Conversation;
use App\Domain\Flows\Enums\FlowExecutionStatus;
use App\Domain\Flows\Enums\FlowStatus;
use App\Domain\Flows\Enums\FlowTriggerType;
use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Models\FlowExecution;
use App\Domain\Flows\Services\VariableGuard;
use App\Domain\Messages\Enums\MessageDirection;
use App\Domain\Messages\Models\Message;
use App\Domain\Tenants\Models\Tenant;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
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

/*
|--------------------------------------------------------------------------
| FASE 13 — UNIDAD 2: DSL seguro, tipos, condition all/any/not (VAR-3/16/17/19)
|--------------------------------------------------------------------------
*/

/**
 * Publica un flujo Inicio → question (field/type dados) → resto del grafo.
 */
function publish_typed_question_flow(Tenant $tenant, string $field, string $type, array $tail): Flow
{
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    make_flow_graph($flow, array_merge([
        ['id' => 'n1', 'type' => 'message', 'name' => 'Inicio', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'question', 'name' => 'Dato', 'config' => ['prompt' => '¿Dato?', 'field' => $field, 'type' => $type]],
    ], $tail['nodes']), array_merge([['from' => 'n1', 'to' => 'n2']], $tail['connections']));

    $flow->forceFill(['status' => FlowStatus::Published->value])->save();

    make_trigger($flow, ['type' => FlowTriggerType::Start->value]);

    return $flow;
}

/**
 * Arranca el flujo y responde a la pregunta; devuelve la ejecución refrescada.
 */
function answer_typed_question(Tenant $tenant, string $answer): FlowExecution
{
    $first = make_inbound_message($tenant, 'Hola');
    $conversation = Conversation::query()->withoutTenantScope()->whereKey($first->conversation_id)->firstOrFail();
    run_flow_engine($tenant, $first, $conversation);

    $reply = make_inbound_message($tenant, $answer);
    $conversation->refresh();
    run_flow_engine($tenant, $reply, $conversation);

    return FlowExecution::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();
}

/**
 * @return Collection<int, Message>
 */
function flow_variables_outbound(Tenant $tenant, ?string $conversationId = null)
{
    $query = Message::query()
        ->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->where('direction', MessageDirection::Outbound->value);

    if ($conversationId !== null) {
        $query->where('conversation_id', $conversationId);
    }

    return $query->orderBy('created_at')->get();
}

test('VAR-2/VAR-3: la captura tipada conserva el tipo declarado en question.config.type', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    publish_typed_question_flow($tenant, 'edad', 'integer', [
        'nodes' => [['id' => 'n3', 'type' => 'end', 'name' => 'Fin']],
        'connections' => [['from' => 'n2', 'to' => 'n3']],
    ]);

    $execution = answer_typed_question($tenant, ' 42 ');

    expect($execution->variables['custom']['edad'])->toBe(42)
        ->and($execution->variables['custom']['edad'])->toBeInt();
});

test('VAR-2/VAR-3: boolean se captura con su tipo real', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    publish_typed_question_flow($tenant, 'vip', 'boolean', [
        'nodes' => [['id' => 'n3', 'type' => 'end', 'name' => 'Fin']],
        'connections' => [['from' => 'n2', 'to' => 'n3']],
    ]);

    $execution = answer_typed_question($tenant, 'sí');

    expect($execution->variables['custom']['vip'])->toBeTrue();
});

test('VAR-2/VAR-3: decimal se captura con su tipo real', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    publish_typed_question_flow($tenant, 'precio', 'decimal', [
        'nodes' => [['id' => 'n3', 'type' => 'end', 'name' => 'Fin']],
        'connections' => [['from' => 'n2', 'to' => 'n3']],
    ]);

    $execution = answer_typed_question($tenant, '10.50');

    expect($execution->variables['custom']['precio'])->toBe(10.5);
});

test('VAR-2: si la coerción tipada falla se conserva la cadena en bruto', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    publish_typed_question_flow($tenant, 'edad', 'integer', [
        'nodes' => [['id' => 'n3', 'type' => 'end', 'name' => 'Fin']],
        'connections' => [['from' => 'n2', 'to' => 'n3']],
    ]);

    $execution = answer_typed_question($tenant, 'abc');

    expect($execution->variables['custom']['edad'])->toBe('abc');
});

test('VAR-2: el valor tipado se interpola con su representación estable', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    publish_typed_question_flow($tenant, 'vip', 'boolean', [
        'nodes' => [
            ['id' => 'n3', 'type' => 'message', 'name' => 'Resultado', 'config' => ['text' => '¿VIP? {{custom.vip}}']],
            ['id' => 'n4', 'type' => 'end', 'name' => 'Fin'],
        ],
        'connections' => [
            ['from' => 'n2', 'to' => 'n3'],
            ['from' => 'n3', 'to' => 'n4'],
        ],
    ]);

    $first = make_inbound_message($tenant, 'Hola');
    $conversation = Conversation::query()->withoutTenantScope()->whereKey($first->conversation_id)->firstOrFail();
    run_flow_engine($tenant, $first, $conversation);

    $reply = make_inbound_message($tenant, 'si');
    $conversation->refresh();
    run_flow_engine($tenant, $reply, $conversation);

    $outbound = flow_variables_outbound($tenant, $conversation->id);

    expect($outbound->last()->body)->toBe('¿VIP? true');
});

test('VAR-17: el webhook interpola el payload pero el URL queda literal (sin SSRF por variables)', function (): void {
    Queue::fake();
    Http::fake();

    $tenant = Tenant::factory()->create();
    publish_typed_question_flow($tenant, 'plan', 'string', [
        'nodes' => [
            ['id' => 'n3', 'type' => 'webhook', 'name' => 'Hook', 'config' => [
                'url' => 'https://example.com/hook/{{custom.plan}}',
                'method' => 'POST',
                'payload' => ['plan' => '{{custom.plan}}'],
            ]],
            ['id' => 'n4', 'type' => 'end', 'name' => 'Fin'],
        ],
        'connections' => [
            ['from' => 'n2', 'to' => 'n3'],
            ['from' => 'n3', 'to' => 'n4'],
        ],
    ]);

    $execution = answer_typed_question($tenant, 'pro');

    expect($execution->status)->toBe(FlowExecutionStatus::Completed);

    // El payload SÍ se interpola; el URL NO se interpola (la variable no llega
    // al destino): la URL se usa literal tal como está en la config del nodo.
    Http::assertSent(function (Request $request): bool {
        return $request->data()['plan'] === 'pro'
            && ! str_contains($request->url(), 'pro')
            && str_contains($request->url(), 'example.com/hook');
    });
});

test('VAR-19: regression — los nodos buttons siguen funcionando con el resolver extendido', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    make_flow_graph($flow, [
        ['id' => 'n1', 'type' => 'message', 'name' => 'Inicio', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'buttons', 'name' => 'Opciones', 'config' => [
            'text' => 'Hola {{contact.name}}, elige',
            'buttons' => [
                ['id' => 'ventas', 'title' => 'Ventas'],
                ['id' => 'soporte', 'title' => 'Soporte'],
            ],
        ]],
        ['id' => 'n3', 'type' => 'message', 'name' => 'Ruta', 'config' => ['text' => 'Perfecto {{contact.name}}']],
        ['id' => 'n4', 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
        ['from' => 'n2', 'to' => 'n3'],
        ['from' => 'n3', 'to' => 'n4'],
    ]);

    $flow->forceFill(['status' => FlowStatus::Published->value])->save();

    make_trigger($flow, ['type' => FlowTriggerType::Start->value]);

    $first = make_inbound_message($tenant, 'Hola');
    $conversation = Conversation::query()->withoutTenantScope()->whereKey($first->conversation_id)->firstOrFail();

    TenantContext::setId($tenant->id);
    try {
        $conversation->contact->forceFill(['name' => 'Ana'])->save();
    } finally {
        TenantContext::clear();
    }

    run_flow_engine($tenant, $first, $conversation);

    $prompt = flow_variables_outbound($tenant, $conversation->id)->last();

    expect($prompt->body)->toContain('Hola Ana, elige')
        ->and($prompt->body)->toContain('1. Ventas');

    $selection = make_inbound_message($tenant, 'Ventas');
    $conversation->refresh();
    run_flow_engine($tenant, $selection, $conversation);

    $outbound = flow_variables_outbound($tenant, $conversation->id);

    expect($outbound->last()->body)->toBe('Perfecto Ana');

    $execution = FlowExecution::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();

    expect($execution->status)->toBe(FlowExecutionStatus::Completed);
});

test('VAR-3: el nodo condition con match any ramifica correctamente end-to-end', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    publish_typed_question_flow($tenant, 'plan', 'string', [
        'nodes' => [
            ['id' => 'n3', 'type' => 'condition', 'name' => 'Acceso', 'config' => [
                'match' => 'any',
                'rules' => [
                    ['field' => 'custom.plan', 'operator' => 'equals', 'value' => 'pro'],
                    ['field' => 'custom.plan', 'operator' => 'equals', 'value' => 'admin'],
                ],
            ]],
            ['id' => 'n4', 'type' => 'message', 'name' => 'OK', 'config' => ['text' => 'Acceso concedido']],
            ['id' => 'n5', 'type' => 'message', 'name' => 'Denegado', 'config' => ['text' => 'Acceso denegado']],
            ['id' => 'n6', 'type' => 'end', 'name' => 'Fin'],
        ],
        'connections' => [
            ['from' => 'n2', 'to' => 'n3'],
            ['from' => 'n3', 'to' => 'n4', 'label' => 'true'],
            ['from' => 'n3', 'to' => 'n5', 'label' => 'false'],
            ['from' => 'n4', 'to' => 'n6'],
            ['from' => 'n5', 'to' => 'n6'],
        ],
    ]);

    $execution = answer_typed_question($tenant, 'admin');

    $outbound = flow_variables_outbound($tenant);

    expect($execution->status)->toBe(FlowExecutionStatus::Completed)
        ->and($outbound->last()->body)->toBe('Acceso concedido');
});

test('VAR-3: el nodo condition con not y match all niega la regla', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    publish_typed_question_flow($tenant, 'plan', 'string', [
        'nodes' => [
            ['id' => 'n3', 'type' => 'condition', 'name' => 'No es pro', 'config' => [
                'rules' => [
                    ['field' => 'custom.plan', 'operator' => 'equals', 'value' => 'pro', 'not' => true],
                ],
            ]],
            ['id' => 'n4', 'type' => 'message', 'name' => 'Promo', 'config' => ['text' => 'Te ofrecemos pro']],
            ['id' => 'n5', 'type' => 'message', 'name' => 'Ya pro', 'config' => ['text' => 'Ya eres pro']],
            ['id' => 'n6', 'type' => 'end', 'name' => 'Fin'],
        ],
        'connections' => [
            ['from' => 'n2', 'to' => 'n3'],
            ['from' => 'n3', 'to' => 'n4', 'label' => 'true'],
            ['from' => 'n3', 'to' => 'n5', 'label' => 'false'],
            ['from' => 'n4', 'to' => 'n6'],
            ['from' => 'n5', 'to' => 'n6'],
        ],
    ]);

    $execution = answer_typed_question($tenant, 'gratis');

    $outbound = flow_variables_outbound($tenant);

    expect($execution->status)->toBe(FlowExecutionStatus::Completed)
        ->and($outbound->last()->body)->toBe('Te ofrecemos pro');
});

test('VAR-3: el nodo condition con starts_with funciona end-to-end', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    publish_typed_question_flow($tenant, 'email', 'string', [
        'nodes' => [
            ['id' => 'n3', 'type' => 'condition', 'name' => 'Dominio', 'config' => [
                'rules' => [
                    ['field' => 'custom.email', 'operator' => 'ends_with', 'value' => '@negocio.com'],
                ],
            ]],
            ['id' => 'n4', 'type' => 'message', 'name' => 'Empresa', 'config' => ['text' => 'Email corporativo']],
            ['id' => 'n5', 'type' => 'message', 'name' => 'Personal', 'config' => ['text' => 'Email personal']],
            ['id' => 'n6', 'type' => 'end', 'name' => 'Fin'],
        ],
        'connections' => [
            ['from' => 'n2', 'to' => 'n3'],
            ['from' => 'n3', 'to' => 'n4', 'label' => 'true'],
            ['from' => 'n3', 'to' => 'n5', 'label' => 'false'],
            ['from' => 'n4', 'to' => 'n6'],
            ['from' => 'n5', 'to' => 'n6'],
        ],
    ]);

    $execution = answer_typed_question($tenant, 'ana@negocio.com');

    $outbound = flow_variables_outbound($tenant);

    expect($execution->status)->toBe(FlowExecutionStatus::Completed)
        ->and($outbound->last()->body)->toBe('Email corporativo');
});
