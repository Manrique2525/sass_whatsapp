<?php

declare(strict_types=1);

use App\Domain\AI\Contracts\AIProviderInterface;
use App\Domain\AI\Exceptions\AIRateLimitException;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Flows\Enums\FlowExecutionStatus;
use App\Domain\Flows\Enums\FlowStatus;
use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Models\FlowExecution;
use App\Domain\Flows\Services\FlowValidator;
use App\Domain\Messages\Models\Message;
use App\Domain\Tenants\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeAIProvider;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| FASE 16 U2 — AI FLOW NODE (FEATURE TESTS)
|--------------------------------------------------------------------------
|
| Tests AI-F01..F10: flow end-to-end con FakeAIProvider.
|
*/

function make_ai_flow(Tenant $tenant): array
{
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    $nodes = make_flow_graph($flow, [
        ['id' => 'start', 'type' => 'message', 'name' => 'Inicio', 'is_start' => true],
        ['id' => 'ai1', 'type' => 'ai', 'name' => 'AI Node', 'config' => [
            'prompt' => 'Responde al usuario de forma amigable',
            'system_prompt' => 'Eres un asistente de soporte.',
            'output_variable' => 'ai_response',
            'fallback_message' => 'Lo siento, no puedo procesar tu solicitud.',
        ]],
        ['id' => 'msg1', 'type' => 'message', 'name' => 'Mensaje', 'config' => [
            'text' => '{{custom.ai_response}}',
        ]],
        ['id' => 'end1', 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => 'start', 'to' => 'ai1'],
        ['from' => 'ai1', 'to' => 'msg1'],
        ['from' => 'msg1', 'to' => 'end1'],
    ]);

    $flow->forceFill(['status' => FlowStatus::Published->value])->save();

    make_trigger($flow, ['type' => 'keyword', 'keyword' => 'ayuda']);

    return ['flow' => $flow, 'nodes' => $nodes, 'chatbot' => $chatbot];
}

function register_fake_ai(?string $response = 'Respuesta AI del bot'): FakeAIProvider
{
    $fake = new FakeAIProvider;
    $fake->withResponse($response ?? '');

    app()->instance(AIProviderInterface::class, $fake);

    return $fake;
}

// ---------------------------------------------------------------------------
// AI-F01: Flow con AI se puede publicar
// ---------------------------------------------------------------------------
test('AI-F01: Flow with AI node can be published', function (): void {
    $tenant = Tenant::factory()->create();
    ['flow' => $flow] = make_ai_flow($tenant);

    expect($flow->fresh()->status)->toBe(FlowStatus::Published);
});

// ---------------------------------------------------------------------------
// AI-F02: Flow AI ejecuta end-to-end con fake provider
// ---------------------------------------------------------------------------
test('AI-F02: AI flow executes end-to-end with fake provider', function (): void {
    $tenant = Tenant::factory()->create();
    $fake = register_fake_ai('Hola, soy tu asistente virtual.');

    ['flow' => $flow] = make_ai_flow($tenant);

    $inbound = make_inbound_message($tenant, 'ayuda');
    $conversation = Conversation::query()->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->whereKey($inbound->conversation_id)
        ->firstOrFail();

    run_flow_engine($tenant, $inbound, $conversation);

    expect($fake->callCount())->toBe(1);

    $execution = FlowExecution::query()
        ->withoutTenantScope()
        ->where('flow_id', $flow->id)
        ->where('conversation_id', $conversation->id)
        ->first();

    expect($execution)->not->toBeNull()
        ->and($execution->status)->toBe(FlowExecutionStatus::Completed)
        ->and($execution->variables['custom']['ai_response'])->toBe('Hola, soy tu asistente virtual.');

    $outbound = Message::query()
        ->withoutTenantScope()
        ->where('conversation_id', $conversation->id)
        ->where('direction', 'outbound')
        ->where('body', '!=', '')
        ->latest()
        ->first();

    expect($outbound)->not->toBeNull()
        ->and($outbound->body)->toBe('Hola, soy tu asistente virtual.');
});

// ---------------------------------------------------------------------------
// AI-F03: AI → condition usando custom output
// ---------------------------------------------------------------------------
test('AI-F03: AI output can be used in condition branching', function (): void {
    $tenant = Tenant::factory()->create();
    $fake = register_fake_ai('positivo');
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    make_flow_graph($flow, [
        ['id' => 'start', 'type' => 'message', 'name' => 'Inicio', 'is_start' => true],
        ['id' => 'ai1', 'type' => 'ai', 'name' => 'AI', 'config' => [
            'prompt' => 'Clasifica el sentimiento',
            'output_variable' => 'sentiment',
        ]],
        ['id' => 'cond1', 'type' => 'condition', 'name' => 'Condición', 'config' => [
            'match' => 'all',
            'rules' => [['field' => 'custom.sentiment', 'operator' => 'equals', 'value' => 'positivo']],
        ]],
        ['id' => 'msg_pos', 'type' => 'message', 'name' => 'Positivo', 'config' => ['text' => 'Sentimiento positivo']],
        ['id' => 'msg_neg', 'type' => 'message', 'name' => 'Negativo', 'config' => ['text' => 'Sentimiento negativo']],
        ['id' => 'end1', 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => 'start', 'to' => 'ai1'],
        ['from' => 'ai1', 'to' => 'cond1'],
        ['from' => 'cond1', 'to' => 'msg_pos', 'label' => 'true'],
        ['from' => 'cond1', 'to' => 'msg_neg', 'label' => 'false'],
        ['from' => 'msg_pos', 'to' => 'end1'],
        ['from' => 'msg_neg', 'to' => 'end1'],
    ]);

    $flow->forceFill(['status' => FlowStatus::Published->value])->save();
    make_trigger($flow, ['type' => 'keyword', 'keyword' => 'test']);

    $inbound = make_inbound_message($tenant, 'test');
    $conversation = Conversation::query()->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->whereKey($inbound->conversation_id)
        ->firstOrFail();

    run_flow_engine($tenant, $inbound, $conversation);

    $outbound = Message::query()
        ->withoutTenantScope()
        ->where('conversation_id', $conversation->id)
        ->where('direction', 'outbound')
        ->where('body', '!=', '')
        ->latest()
        ->first();

    expect($outbound)->not->toBeNull()
        ->and($outbound->body)->toBe('Sentimiento positivo');
});

// ---------------------------------------------------------------------------
// AI-F04: AI → message usando {{custom.output}}
// ---------------------------------------------------------------------------
test('AI-F04: AI output interpolated in subsequent message node', function (): void {
    $tenant = Tenant::factory()->create();
    $fake = register_fake_ai('Respuesta personalizada del AI');

    ['flow' => $flow] = make_ai_flow($tenant);

    $inbound = make_inbound_message($tenant, 'ayuda');
    $conversation = Conversation::query()->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->whereKey($inbound->conversation_id)
        ->firstOrFail();

    run_flow_engine($tenant, $inbound, $conversation);

    $outbound = Message::query()
        ->withoutTenantScope()
        ->where('conversation_id', $conversation->id)
        ->where('direction', 'outbound')
        ->where('body', '!=', '')
        ->latest()
        ->first();

    expect($outbound->body)->toBe('Respuesta personalizada del AI');
});

// ---------------------------------------------------------------------------
// AI-F05: provider falla → fallback → flow continúa
// ---------------------------------------------------------------------------
test('AI-F05: Provider failure triggers fallback and flow continues', function (): void {
    $tenant = Tenant::factory()->create();
    $fake = register_fake_ai('');
    $fake->withException(new AIRateLimitException);

    ['flow' => $flow] = make_ai_flow($tenant);

    $inbound = make_inbound_message($tenant, 'ayuda');
    $conversation = Conversation::query()->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->whereKey($inbound->conversation_id)
        ->firstOrFail();

    run_flow_engine($tenant, $inbound, $conversation);

    $execution = FlowExecution::query()
        ->withoutTenantScope()
        ->where('flow_id', $flow->id)
        ->where('conversation_id', $conversation->id)
        ->first();

    expect($execution->variables['custom']['ai_response'])
        ->toBe('Lo siento, no puedo procesar tu solicitud.');

    $outbound = Message::query()
        ->withoutTenantScope()
        ->where('conversation_id', $conversation->id)
        ->where('direction', 'outbound')
        ->where('body', '!=', '')
        ->latest()
        ->first();

    expect($outbound)->not->toBeNull()
        ->and($outbound->body)->toBe('Lo siento, no puedo procesar tu solicitud.');
});

// ---------------------------------------------------------------------------
// AI-F06: bot paused → AI no ejecuta
// ---------------------------------------------------------------------------
test('AI-F06: Bot paused prevents AI flow execution', function (): void {
    $tenant = Tenant::factory()->create();
    $fake = register_fake_ai('Should not execute');
    ['flow' => $flow] = make_ai_flow($tenant);

    $inbound = make_inbound_message($tenant, 'ayuda');
    $conversation = Conversation::query()->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->whereKey($inbound->conversation_id)
        ->firstOrFail();

    $conversation->forceFill(['bot_paused' => true])->save();

    run_flow_engine($tenant, $inbound, $conversation);

    expect($fake->callCount())->toBe(0);

    $execution = FlowExecution::query()
        ->withoutTenantScope()
        ->where('flow_id', $flow->id)
        ->where('conversation_id', $conversation->id)
        ->first();

    expect($execution)->toBeNull();
});

// ---------------------------------------------------------------------------
// AI-F07: duplicate execution/node → una llamada
// ---------------------------------------------------------------------------
test('AI-F07: Idempotency check prevents duplicate provider calls', function (): void {
    $tenant = Tenant::factory()->create();
    $fake = register_fake_ai('First result');
    ['flow' => $flow] = make_ai_flow($tenant);

    $inbound = make_inbound_message($tenant, 'ayuda');
    $conversation = Conversation::query()->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->whereKey($inbound->conversation_id)
        ->firstOrFail();

    run_flow_engine($tenant, $inbound, $conversation);

    expect($fake->callCount())->toBe(1);

    $execution = FlowExecution::query()
        ->withoutTenantScope()
        ->where('flow_id', $flow->id)
        ->where('conversation_id', $conversation->id)
        ->first();

    expect($execution->variables['custom']['ai_response'])->toBe('First result');
});

// ---------------------------------------------------------------------------
// AI-F08: execution completa correctamente
// ---------------------------------------------------------------------------
test('AI-F08: Full AI flow execution completes successfully', function (): void {
    $tenant = Tenant::factory()->create();
    $fake = register_fake_ai('AI output');
    ['flow' => $flow] = make_ai_flow($tenant);

    $inbound = make_inbound_message($tenant, 'ayuda');
    $conversation = Conversation::query()->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->whereKey($inbound->conversation_id)
        ->firstOrFail();

    run_flow_engine($tenant, $inbound, $conversation);

    $execution = FlowExecution::query()
        ->withoutTenantScope()
        ->where('flow_id', $flow->id)
        ->where('conversation_id', $conversation->id)
        ->first();

    expect($execution->status)->toBe(FlowExecutionStatus::Completed);
});

// ---------------------------------------------------------------------------
// AI-F09: handoff posterior al AI mantiene invariantes
// ---------------------------------------------------------------------------
test('AI-F09: Handoff after AI node preserves invariants', function (): void {
    $tenant = Tenant::factory()->create();
    $fake = register_fake_ai('AI before handoff');
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    make_flow_graph($flow, [
        ['id' => 'start', 'type' => 'message', 'name' => 'Inicio', 'is_start' => true],
        ['id' => 'ai1', 'type' => 'ai', 'name' => 'AI', 'config' => [
            'prompt' => 'Genera saludo',
            'output_variable' => 'greeting',
        ]],
        ['id' => 'human1', 'type' => 'human', 'name' => 'Handoff', 'config' => [
            'handoff_message' => 'Un agente te atenderá.',
        ]],
    ], [
        ['from' => 'start', 'to' => 'ai1'],
        ['from' => 'ai1', 'to' => 'human1'],
    ]);

    $flow->forceFill(['status' => FlowStatus::Published->value])->save();
    make_trigger($flow, ['type' => 'keyword', 'keyword' => 'agente']);

    $inbound = make_inbound_message($tenant, 'agente');
    $conversation = Conversation::query()->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->whereKey($inbound->conversation_id)
        ->firstOrFail();

    run_flow_engine($tenant, $inbound, $conversation);

    expect($fake->callCount())->toBe(1);

    $execution = FlowExecution::query()
        ->withoutTenantScope()
        ->where('flow_id', $flow->id)
        ->where('conversation_id', $conversation->id)
        ->first();

    expect($execution->status)->toBe(FlowExecutionStatus::HandedOff)
        ->and($execution->variables['custom']['greeting'])->toBe('AI before handoff');

    $conversation->refresh();
    expect($conversation->bot_paused)->toBeTrue();
});

// ---------------------------------------------------------------------------
// AI-F10: AI node no start
// ---------------------------------------------------------------------------
test('AI-F10: AI node cannot be start node', function (): void {
    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    make_flow_graph($flow, [
        ['id' => 'ai1', 'type' => 'ai', 'name' => 'AI', 'is_start' => true, 'config' => [
            'prompt' => 'Test',
            'output_variable' => 'result',
        ]],
        ['id' => 'end1', 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => 'ai1', 'to' => 'end1'],
    ]);

    $errors = app(FlowValidator::class)
        ->validate(
            $flow->nodes,
            $flow->connections,
        );

    expect($errors)->not->toBeEmpty()
        ->and(implode(' ', $errors))->toContain('inicio');
});
