<?php

declare(strict_types=1);

use App\Application\Flows\Services\FlowEngine;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Flows\Enums\FlowExecutionStatus;
use App\Domain\Flows\Enums\FlowStatus;
use App\Domain\Flows\Enums\FlowTriggerType;
use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Models\FlowExecution;
use App\Domain\Flows\Models\FlowExecutionLog;
use App\Domain\Messages\Enums\MessageDirection;
use App\Domain\Messages\Models\Message;
use App\Domain\Tenants\Models\Tenant;
use App\Infrastructure\Tenancy\TenantContext;
use App\Jobs\ContinueFlowExecution;
use App\Jobs\SendWhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| FASE 11 — MOTOR DE FLUJOS: ejecución nodo a nodo
|--------------------------------------------------------------------------
*/

function publish_flow(Flow $flow, array $nodes, array $connections): Flow
{
    make_flow_graph($flow, $nodes, $connections);

    $flow->forceFill(['status' => FlowStatus::Published->value])->save();

    return $flow;
}

function engine_conversation_for(Message $message): Conversation
{
    return Conversation::query()
        ->withoutTenantScope()
        ->whereKey($message->conversation_id)
        ->firstOrFail();
}

function continue_engine(Tenant $tenant, FlowExecution $execution, string $mode = 'delay'): void
{
    TenantContext::setId($tenant->id);

    try {
        app(FlowEngine::class)->continueExecution($tenant, $execution, $mode);
    } finally {
        TenantContext::clear();
    }
}

function flow_outbound(Tenant $tenant, ?string $conversationId = null)
{
    $query = Message::query()
        ->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->where('direction', MessageDirection::Outbound->value);

    if ($conversationId !== null) {
        $query->where('conversation_id', $conversationId);
    }

    return $query;
}

test('FLOW-1: un trigger keyword en el primer mensaje arranca el flujo y lo completa', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    publish_flow($flow, [
        ['id' => 'n1', 'type' => 'message', 'name' => 'Saludo', 'config' => ['text' => '¡Hola!'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
    ]);

    make_trigger($flow, ['type' => FlowTriggerType::Keyword->value, 'keyword' => 'ofertas']);

    $message = make_inbound_message($tenant, 'Quiero ver las ofertas');
    $conversation = engine_conversation_for($message);

    run_flow_engine($tenant, $message, $conversation);

    $execution = FlowExecution::query()
        ->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->where('conversation_id', $conversation->id)
        ->firstOrFail();

    expect($execution->status)->toBe(FlowExecutionStatus::Completed)
        ->and($conversation->fresh()->flow_execution_id)->toBeNull();

    $outbound = flow_outbound($tenant, $conversation->id)->get();

    expect($outbound)->toHaveCount(1)
        ->and($outbound->first()->body)->toBe('¡Hola!');

    Queue::assertPushed(SendWhatsAppMessage::class);
});

test('FLOW-2: el motor captura y resuelve variables de contacto y custom', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    publish_flow($flow, [
        ['id' => 'n1', 'type' => 'message', 'name' => 'Bienvenida', 'config' => ['text' => 'Bienvenido'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'question', 'name' => 'Clave', 'config' => ['prompt' => '¿Tu clave?', 'field' => 'clave']],
        ['id' => 'n3', 'type' => 'message', 'name' => 'Resuelve', 'config' => ['text' => 'Hola {{contact.name}}, clave {{custom.clave}}']],
        ['id' => 'n4', 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
        ['from' => 'n2', 'to' => 'n3'],
        ['from' => 'n3', 'to' => 'n4'],
    ]);

    make_trigger($flow, ['type' => FlowTriggerType::Start->value]);

    $message = make_inbound_message($tenant, 'Hola', '15550001111');
    $conversation = engine_conversation_for($message);

    TenantContext::setId($tenant->id);
    try {
        $conversation->contact->forceFill(['name' => 'Ana'])->save();
    } finally {
        TenantContext::clear();
    }

    run_flow_engine($tenant, $message, $conversation);

    $execution = FlowExecution::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();
    expect($execution->status)->toBe(FlowExecutionStatus::Waiting);

    $answer = make_inbound_message($tenant, 'abc', '15550001111');
    $conversation->refresh();
    run_flow_engine($tenant, $answer, $conversation);

    $outbound = flow_outbound($tenant, $conversation->id)->orderBy('created_at')->get();

    expect($outbound->last()->body)->toBe('Hola Ana, clave abc');

    $execution->refresh();
    expect($execution->variables['custom']['clave'])->toBe('abc');
});

test('FLOW-3: un nodo question espera la respuesta y la captura en custom', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    publish_flow($flow, [
        ['id' => 'n1', 'type' => 'message', 'name' => 'Inicio', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'question', 'name' => 'Pregunta', 'config' => ['prompt' => '¿Tu nombre?', 'field' => 'nombre']],
        ['id' => 'n3', 'type' => 'message', 'name' => 'Gracias', 'config' => ['text' => 'Gracias {{custom.nombre}}']],
        ['id' => 'n4', 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
        ['from' => 'n2', 'to' => 'n3'],
        ['from' => 'n3', 'to' => 'n4'],
    ]);

    make_trigger($flow, ['type' => FlowTriggerType::Start->value]);

    $first = make_inbound_message($tenant, 'Hola');
    $conversation = engine_conversation_for($first);

    run_flow_engine($tenant, $first, $conversation);

    $execution = FlowExecution::query()
        ->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->firstOrFail();

    expect($execution->status)->toBe(FlowExecutionStatus::Waiting);

    $answer = make_inbound_message($tenant, 'Carlos');
    $conversation->refresh();
    run_flow_engine($tenant, $answer, $conversation);

    $execution->refresh();

    expect($execution->status)->toBe(FlowExecutionStatus::Completed)
        ->and($execution->variables['custom']['nombre'])->toBe('Carlos');

    $outbound = flow_outbound($tenant, $conversation->id)->orderBy('created_at')->get();

    expect($outbound)->toHaveCount(3)
        ->and($outbound->last()->body)->toBe('Gracias Carlos');
});

test('FLOW-4: nodo buttons — respuesta correcta avanza, incorrecta reenvía opciones', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    publish_flow($flow, [
        ['id' => 'n1', 'type' => 'message', 'name' => 'Inicio', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'buttons', 'name' => 'Opciones', 'config' => [
            'text' => 'Elige',
            'buttons' => [
                ['id' => 'ventas', 'title' => 'Ventas'],
                ['id' => 'soporte', 'title' => 'Soporte'],
            ],
        ]],
        ['id' => 'n3', 'type' => 'message', 'name' => 'Ruta', 'config' => ['text' => 'Vas a ventas']],
        ['id' => 'n4', 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
        ['from' => 'n2', 'to' => 'n3'],
        ['from' => 'n3', 'to' => 'n4'],
    ]);

    make_trigger($flow, ['type' => FlowTriggerType::Start->value]);

    $first = make_inbound_message($tenant, 'Hola');
    $conversation = engine_conversation_for($first);

    run_flow_engine($tenant, $first, $conversation);

    $execution = FlowExecution::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();
    expect($execution->status)->toBe(FlowExecutionStatus::Waiting);

    $outboundBefore = flow_outbound($tenant, $conversation->id)->count();

    // Respuesta que no coincide: se reenvían opciones y se mantiene waiting.
    $wrong = make_inbound_message($tenant, 'no sé');
    $conversation->refresh();
    run_flow_engine($tenant, $wrong, $conversation);

    $execution->refresh();

    expect($execution->status)->toBe(FlowExecutionStatus::Waiting);

    $outboundMid = flow_outbound($tenant, $conversation->id)->count();

    expect($outboundMid)->toBe($outboundBefore + 1);

    // Respuesta que coincide con el título: avanza.
    $right = make_inbound_message($tenant, 'Ventas');
    $conversation->refresh();
    run_flow_engine($tenant, $right, $conversation);

    $execution->refresh();

    expect($execution->status)->toBe(FlowExecutionStatus::Completed);

    $outbound = flow_outbound($tenant, $conversation->id)->orderBy('created_at')->get();

    expect($outbound->last()->body)->toBe('Vas a ventas');
});

test('FLOW-5: el nodo condition ramifica por true/false', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    publish_flow($flow, [
        ['id' => 'n1', 'type' => 'message', 'name' => 'Inicio', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'condition', 'name' => '¿VIP?', 'config' => [
            'rules' => [['field' => 'custom.vip', 'operator' => 'equals', 'value' => 'si']],
        ]],
        ['id' => 'n3', 'type' => 'message', 'name' => 'VIP', 'config' => ['text' => 'Bienvenido VIP']],
        ['id' => 'n4', 'type' => 'message', 'name' => 'Normal', 'config' => ['text' => 'Bienvenido']],
        ['id' => 'n5', 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
        ['from' => 'n2', 'to' => 'n3', 'label' => 'true'],
        ['from' => 'n2', 'to' => 'n4', 'label' => 'false'],
        ['from' => 'n3', 'to' => 'n5'],
        ['from' => 'n4', 'to' => 'n5'],
    ]);

    make_trigger($flow, ['type' => FlowTriggerType::Start->value]);

    $first = make_inbound_message($tenant, 'Hola');
    $conversation = engine_conversation_for($first);

    run_flow_engine($tenant, $first, $conversation);

    $outbound = flow_outbound($tenant, $conversation->id)->get();

    expect($outbound->last()->body)->toBe('Bienvenido');
});

test('FLOW-6: el nodo delay espera y continúa vía ContinueFlowExecution', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    publish_flow($flow, [
        ['id' => 'n1', 'type' => 'message', 'name' => 'Inicio', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'delay', 'name' => 'Espera', 'config' => ['seconds' => 30]],
        ['id' => 'n3', 'type' => 'message', 'name' => 'Luego', 'config' => ['text' => 'Pasó el delay']],
        ['id' => 'n4', 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
        ['from' => 'n2', 'to' => 'n3'],
        ['from' => 'n3', 'to' => 'n4'],
    ]);

    make_trigger($flow, ['type' => FlowTriggerType::Start->value]);

    $first = make_inbound_message($tenant, 'Hola');
    $conversation = engine_conversation_for($first);

    run_flow_engine($tenant, $first, $conversation);

    $execution = FlowExecution::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();

    expect($execution->status)->toBe(FlowExecutionStatus::Waiting);

    Queue::assertPushed(ContinueFlowExecution::class, function (ContinueFlowExecution $job) use ($execution): bool {
        return $job->executionId === $execution->id && $job->mode === 'delay';
    });

    // La continuación avanza pasando el delay.
    continue_engine($tenant, $execution, 'delay');

    $execution->refresh();

    expect($execution->status)->toBe(FlowExecutionStatus::Completed);

    $outbound = flow_outbound($tenant, $conversation->id)->orderBy('created_at')->get();

    expect($outbound->last()->body)->toBe('Pasó el delay');
});

test('FLOW-7: fallo del webhook reintenta con backoff y agota máx 3 reintentos', function (): void {
    Queue::fake();
    Http::fake(['https://example.com/hook' => Http::response(['error' => 'boom'], 500)]);

    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    publish_flow($flow, [
        ['id' => 'n1', 'type' => 'message', 'name' => 'Inicio', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'webhook', 'name' => 'Hook', 'config' => ['url' => 'https://example.com/hook', 'method' => 'POST']],
        ['id' => 'n3', 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
        ['from' => 'n2', 'to' => 'n3'],
    ]);

    make_trigger($flow, ['type' => FlowTriggerType::Start->value]);

    $first = make_inbound_message($tenant, 'Hola');
    $conversation = engine_conversation_for($first);

    run_flow_engine($tenant, $first, $conversation);

    $execution = FlowExecution::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();

    expect($execution->attempts)->toBe(1);

    Queue::assertPushed(ContinueFlowExecution::class, fn (ContinueFlowExecution $job): bool => $job->mode === 'retry');

    // Reintentos 2, 3 y agotamiento (4º intento → failed).
    continue_engine($tenant, $execution, 'retry');
    $execution->refresh();
    expect($execution->attempts)->toBe(2);

    continue_engine($tenant, $execution, 'retry');
    $execution->refresh();
    expect($execution->attempts)->toBe(3);

    continue_engine($tenant, $execution, 'retry');
    $execution->refresh();

    expect($execution->status)->toBe(FlowExecutionStatus::Failed)
        ->and($execution->attempts)->toBe(4)
        ->and($conversation->fresh()->flow_execution_id)->toBeNull();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://example.com/hook');
});

test('FLOW-8: CRITICO — el webhook bloquea destinos internos (SSRF) y falla la ejecución', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    publish_flow($flow, [
        ['id' => 'n1', 'type' => 'message', 'name' => 'Inicio', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'webhook', 'name' => 'Hook', 'config' => ['url' => 'http://127.0.0.1:8080/admin', 'method' => 'POST']],
        ['id' => 'n3', 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
        ['from' => 'n2', 'to' => 'n3'],
    ]);

    make_trigger($flow, ['type' => FlowTriggerType::Start->value]);

    $first = make_inbound_message($tenant, 'Hola');
    $conversation = engine_conversation_for($first);

    run_flow_engine($tenant, $first, $conversation);

    $execution = FlowExecution::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();

    expect($execution->status)->toBe(FlowExecutionStatus::Failed);

    $log = FlowExecutionLog::query()->withoutTenantScope()->where('execution_id', $execution->id)->where('event', 'execution.failed')->first();

    expect($log?->payload['reason'])->toBe('webhook_blocked');
});

test('FLOW-9: el nodo human transfiere a un agente (handoff básico)', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    publish_flow($flow, [
        ['id' => 'n1', 'type' => 'message', 'name' => 'Inicio', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'human', 'name' => 'Humano'],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
    ]);

    make_trigger($flow, ['type' => FlowTriggerType::Start->value]);

    $first = make_inbound_message($tenant, 'Hola');
    $conversation = engine_conversation_for($first);

    run_flow_engine($tenant, $first, $conversation);

    $execution = FlowExecution::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();

    expect($execution->status)->toBe(FlowExecutionStatus::HandedOff)
        ->and($conversation->fresh()->bot_paused)->toBeTrue()
        ->and($conversation->fresh()->status->value)->toBe('open')
        ->and($conversation->fresh()->flow_execution_id)->toBeNull();
});

test('FLOW-10: el nodo tag asigna etiquetas al contacto', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    publish_flow($flow, [
        ['id' => 'n1', 'type' => 'message', 'name' => 'Inicio', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'tag', 'name' => 'Etiqueta', 'config' => ['tags' => ['vip', 'interesado']]],
        ['id' => 'n3', 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
        ['from' => 'n2', 'to' => 'n3'],
    ]);

    make_trigger($flow, ['type' => FlowTriggerType::Start->value]);

    $first = make_inbound_message($tenant, 'Hola');
    $conversation = engine_conversation_for($first);

    run_flow_engine($tenant, $first, $conversation);

    $execution = FlowExecution::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();

    expect($execution->status)->toBe(FlowExecutionStatus::Completed);

    $contact = $conversation->contact;

    TenantContext::setId($tenant->id);
    try {
        expect($contact->tags()->pluck('name')->all())->toEqualCanonicalizing(['vip', 'interesado']);
    } finally {
        TenantContext::clear();
    }
});

test('FLOW-11: CRITICO — reprocesar el mismo inbound no avanza dos veces', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    publish_flow($flow, [
        ['id' => 'n1', 'type' => 'message', 'name' => 'Inicio', 'config' => ['text' => 'Primero'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'question', 'name' => 'Pregunta', 'config' => ['prompt' => '¿Algo más?', 'field' => 'mas']],
        ['id' => 'n3', 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
        ['from' => 'n2', 'to' => 'n3'],
    ]);

    make_trigger($flow, ['type' => FlowTriggerType::Start->value]);

    $first = make_inbound_message($tenant, 'Hola');
    $conversation = engine_conversation_for($first);

    run_flow_engine($tenant, $first, $conversation);
    run_flow_engine($tenant, $first, $conversation->fresh());

    $execution = FlowExecution::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();

    expect($execution->status)->toBe(FlowExecutionStatus::Waiting);

    $outbound = flow_outbound($tenant, $conversation->id)->count();

    expect($outbound)->toBe(2);
});

test('FLOW-12: una conversación con bot pausado ignora el motor', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    publish_flow($flow, [
        ['id' => 'n1', 'type' => 'message', 'name' => 'Inicio', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
    ]);

    make_trigger($flow, ['type' => FlowTriggerType::Start->value]);

    $first = make_inbound_message($tenant, 'Hola');
    $conversation = engine_conversation_for($first);

    $conversation->forceFill(['bot_paused' => true])->save();

    run_flow_engine($tenant, $first, $conversation->fresh());

    expect(FlowExecution::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('FLOW-13: CRITICO — un inbound de tenant A jamás dispara un flujo de tenant B', function (): void {
    Queue::fake();

    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $chatbot = make_chatbot($tenantA);
    $flow = make_flow($tenantA, $chatbot);
    publish_flow($flow, [
        ['id' => 'n1', 'type' => 'message', 'name' => 'Inicio', 'config' => ['text' => 'Oferta'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
    ]);
    make_trigger($flow, ['type' => FlowTriggerType::NewMessage->value]);

    // El tenant B no tiene flujos: el inbound de B no hace nada.
    $messageB = make_inbound_message($tenantB, 'Hola');
    $conversationB = engine_conversation_for($messageB);

    run_flow_engine($tenantB, $messageB, $conversationB);

    expect(FlowExecution::query()->withoutTenantScope()->where('tenant_id', $tenantB->id)->count())->toBe(0)
        ->and(FlowExecution::query()->withoutTenantScope()->where('tenant_id', $tenantA->id)->count())->toBe(0);

    // El inbound de A dispara SOLO el flujo de A.
    $messageA = make_inbound_message($tenantA, 'Hola');
    $conversationA = engine_conversation_for($messageA);

    run_flow_engine($tenantA, $messageA, $conversationA);

    expect(FlowExecution::query()->withoutTenantScope()->where('tenant_id', $tenantA->id)->count())->toBe(1)
        ->and(FlowExecution::query()->withoutTenantScope()->where('tenant_id', $tenantB->id)->count())->toBe(0);

    expect(flow_outbound($tenantB)->count())->toBe(0);
});

test('FLOW-14: max_steps excede el límite y marca la ejecución failed', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot, ['config' => ['max_steps' => 5]]);

    // Grafo con ciclo síncrono (el validador lo rechazaría; el motor debe
    // defenderse igualmente por si la data se corrompe).
    make_flow_graph($flow, [
        ['id' => 'n1', 'type' => 'message', 'name' => 'Inicio', 'config' => ['text' => 'otra vez'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'message', 'name' => 'Loop', 'config' => ['text' => 'otra vez']],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
        ['from' => 'n2', 'to' => 'n2'],
    ]);

    $flow->forceFill(['status' => FlowStatus::Published->value])->save();

    make_trigger($flow, ['type' => FlowTriggerType::Start->value]);

    $first = make_inbound_message($tenant, 'Hola');
    $conversation = engine_conversation_for($first);

    run_flow_engine($tenant, $first, $conversation);

    $execution = FlowExecution::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();

    expect($execution->status)->toBe(FlowExecutionStatus::Failed);

    $log = FlowExecutionLog::query()->withoutTenantScope()->where('execution_id', $execution->id)->where('event', 'execution.failed')->first();

    expect($log?->payload['reason'])->toBe('max_steps_exceeded');
});

test('FLOW-15: sin trigger que matchee, el motor no crea ejecución', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);
    publish_flow($flow, [
        ['id' => 'n1', 'type' => 'message', 'name' => 'Inicio', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
    ]);

    // Keyword que no aparece en el mensaje → sin disparo.
    make_trigger($flow, ['type' => FlowTriggerType::Keyword->value, 'keyword' => 'oferta']);

    $first = make_inbound_message($tenant, 'Hola, ¿qué tal?');
    $conversation = engine_conversation_for($first);

    run_flow_engine($tenant, $first, $conversation);

    expect(FlowExecution::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(0)
        ->and($conversation->fresh()->flow_execution_id)->toBeNull();
});

test('UNIDAD 5: la auditoría del webhook nunca registra la query con secretos del URL', function (): void {
    Queue::fake();
    Http::fake(['https://example.com/hook*' => Http::response(['ok' => true], 200)]);

    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    publish_flow($flow, [
        ['id' => 'n1', 'type' => 'message', 'name' => 'Inicio', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'webhook', 'name' => 'Hook', 'config' => [
            'url' => 'https://example.com/hook?api_key=top-secret',
            'method' => 'GET',
        ]],
        ['id' => 'n3', 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
        ['from' => 'n2', 'to' => 'n3'],
    ]);

    make_trigger($flow, ['type' => FlowTriggerType::Start->value]);

    $first = make_inbound_message($tenant, 'Hola');
    $conversation = engine_conversation_for($first);

    run_flow_engine($tenant, $first, $conversation);

    $execution = FlowExecution::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();
    expect($execution->status)->toBe(FlowExecutionStatus::Completed);

    $audit = AuditLog::query()->where('action', 'flow.webhook_called')->where('subject_id', $execution->id)->first();

    expect($audit?->data['url'])->toBe('https://example.com/hook');

    foreach (FlowExecutionLog::query()->withoutTenantScope()->where('execution_id', $execution->id)->get() as $log) {
        expect(json_encode($log->payload))->not->toContain('top-secret');
    }
});

test('UNIDAD 5: el error del webhook en logs no expone la query con secretos', function (): void {
    Queue::fake();
    Http::fake(['https://example.com/hook*' => Http::response(['error' => 'boom'], 500)]);

    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    publish_flow($flow, [
        ['id' => 'n1', 'type' => 'message', 'name' => 'Inicio', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'webhook', 'name' => 'Hook', 'config' => [
            'url' => 'https://example.com/hook?token=secreto-de-query',
            'method' => 'POST',
        ]],
        ['id' => 'n3', 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
        ['from' => 'n2', 'to' => 'n3'],
    ]);

    make_trigger($flow, ['type' => FlowTriggerType::Start->value]);

    $first = make_inbound_message($tenant, 'Hola');
    $conversation = engine_conversation_for($first);

    run_flow_engine($tenant, $first, $conversation);

    $execution = FlowExecution::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();
    expect($execution->attempts)->toBe(1);

    $retryLog = FlowExecutionLog::query()->withoutTenantScope()->where('execution_id', $execution->id)->where('event', 'step_retry')->first();

    expect($retryLog?->payload['error'])
        ->toContain('https://example.com/hook')
        ->not->toContain('secreto-de-query')
        ->not->toContain('token=');

    foreach (FlowExecutionLog::query()->withoutTenantScope()->where('execution_id', $execution->id)->get() as $log) {
        expect(json_encode($log->payload))->not->toContain('secreto-de-query');
    }
});
