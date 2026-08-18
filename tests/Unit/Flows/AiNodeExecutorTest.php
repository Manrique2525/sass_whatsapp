<?php

declare(strict_types=1);

use App\Application\Flows\Services\AiPromptBuilder;
use App\Application\Flows\Services\Executors\AiNodeExecutor;
use App\Domain\AI\Exceptions\AIAuthFailedException;
use App\Domain\AI\Exceptions\AIRateLimitException;
use App\Domain\AI\ValueObjects\AIRequest;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Flows\Enums\FlowNodeType;
use App\Domain\Flows\Models\Chatbot;
use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Models\FlowExecution;
use App\Domain\Flows\Models\FlowNode;
use App\Domain\Flows\Services\VariableResolver;
use App\Domain\Flows\ValueObjects\NodeExecutionContext;
use App\Domain\Messages\Models\Message;
use App\Domain\Tenants\Models\Tenant;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Tests\Fakes\FakeAIProvider;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
});

/*
|--------------------------------------------------------------------------
| FASE 16 U2 — AI NODE RUNTIME (UNIT TESTS)
|--------------------------------------------------------------------------
|
| Tests AI-01..AI-15: executor directo con mocks, sin FlowEngine.
|
*/

function ai_context(array $nodeConfig = [], array $custom = []): NodeExecutionContext
{
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    $contact = Contact::query()->create([
        'name' => 'Juan Perez',
        'phone' => '+5215551234567',
        'email' => 'juan@test.com',
    ]);

    $business = $tenant->businessProfile()->create([
        'name' => 'Mi Negocio',
        'description' => 'Tienda de ropa',
        'category' => 'retail',
    ]);

    $conversation = Conversation::query()->create([
        'contact_id' => $contact->id,
        'status' => 'open',
    ]);

    $chatbot = Chatbot::query()
        ->where('tenant_id', $tenant->id)
        ->first();

    if ($chatbot === null) {
        $chatbot = Chatbot::query()->create([
            'name' => 'Test Chatbot',
        ]);
    }

    $flow = Flow::query()->create([
        'chatbot_id' => $chatbot->id,
        'name' => 'Test Flow',
        'status' => 'published',
    ]);

    $node = new FlowNode([
        'flow_id' => $flow->id,
        'type' => FlowNodeType::AI->value,
        'name' => 'AI Node',
        'position_x' => 0,
        'position_y' => 0,
        'config' => $nodeConfig,
        'is_start' => false,
    ]);
    $node->save();

    $execution = FlowExecution::query()->create([
        'flow_id' => $flow->id,
        'conversation_id' => $conversation->id,
        'current_node_id' => $node->id,
        'status' => 'running',
        'variables' => ['custom' => $custom],
        'attempts' => 0,
    ]);

    return new NodeExecutionContext(
        tenant: $tenant,
        node: $node,
        execution: $execution,
        conversation: $conversation,
        contact: $contact,
        business: $business,
        custom: $custom,
    );
}

function create_chatbot(Tenant $tenant): Chatbot
{
    TenantContext::setId($tenant->id);
    try {
        return Chatbot::query()->create([
            'name' => 'Test Chatbot',
        ]);
    } finally {
        TenantContext::clear();
    }
}

function make_executor(?FakeAIProvider $fake = null): AiNodeExecutor
{
    $fake ??= new FakeAIProvider;

    return new AiNodeExecutor(
        provider: $fake,
        promptBuilder: new AiPromptBuilder(new VariableResolver),
    );
}

// ---------------------------------------------------------------------------
// AI-01: Executor llama provider
// ---------------------------------------------------------------------------
test('AI-01: AiNodeExecutor llama al provider con AIRequest', function (): void {
    $fake = new FakeAIProvider;
    $fake->withResponse('Respuesta IA');
    $executor = make_executor($fake);
    $context = ai_context(['prompt' => 'Hola', 'output_variable' => 'respuesta']);

    $executor->execute($context);

    expect($fake->callCount())->toBe(1)
        ->and($fake->lastRequest())->toBeInstanceOf(AIRequest::class)
        ->and($fake->lastRequest()->prompt)->toContain('Hola');
});

// ---------------------------------------------------------------------------
// AI-02: Output se guarda en custom
// ---------------------------------------------------------------------------
test('AI-02: Output del AI se persiste en custom.{output_variable}', function (): void {
    $fake = new FakeAIProvider;
    $fake->withResponse('Resultado guardado');
    $executor = make_executor($fake);
    $context = ai_context(['prompt' => 'Genera texto', 'output_variable' => 'ai_result']);

    $executor->execute($context);

    $execution = $context->execution->fresh();
    expect($execution->variables['custom']['ai_result'])->toBe('Resultado guardado');
});

// ---------------------------------------------------------------------------
// AI-03: Prompt variables resueltas
// ---------------------------------------------------------------------------
test('AI-03: Variables del prompt se resuelven correctamente', function (): void {
    $fake = new FakeAIProvider;
    $fake->withResponse('OK');
    $executor = make_executor($fake);
    $context = ai_context([
        'prompt' => 'Hola {{contact.name}}, bienvenido a {{business.name}}',
        'output_variable' => 'greeting',
    ]);

    $executor->execute($context);

    expect($fake->lastRequest()->prompt)->toContain('Juan Perez')
        ->and($fake->lastRequest()->prompt)->toContain('Mi Negocio');
});

// ---------------------------------------------------------------------------
// AI-04: output_variable inválida rechazada
// ---------------------------------------------------------------------------
test('AI-04: output_variable inválida produce fallback sin llamar provider', function (): void {
    $fake = new FakeAIProvider;
    $fake->withResponse('No debería llegar');
    $executor = make_executor($fake);
    $context = ai_context(['prompt' => 'Test', 'output_variable' => '__proto__']);

    $result = $executor->execute($context);

    expect($fake->callCount())->toBe(0)
        ->and($result->state)->toBe('continue');
});

// ---------------------------------------------------------------------------
// AI-05: respuesta vacía → fallback
// ---------------------------------------------------------------------------
test('AI-05: Provider con respuesta vacía aplica fallback', function (): void {
    $fake = new FakeAIProvider;
    $fake->withResponse('');
    $executor = make_executor($fake);
    $context = ai_context([
        'prompt' => 'Test',
        'output_variable' => 'result',
        'fallback_message' => 'Fallback aplicado',
    ]);

    $result = $executor->execute($context);

    expect($result->state)->toBe('continue')
        ->and($context->execution->fresh()->variables['custom']['result'])->toBe('Fallback aplicado');
});

// ---------------------------------------------------------------------------
// AI-06: provider timeout → fallback
// ---------------------------------------------------------------------------
test('AI-06: Provider timeout produce fallback', function (): void {
    $fake = new FakeAIProvider;
    $fake->withException(new ConnectionException('Connection timed out.'));
    $executor = make_executor($fake);
    $context = ai_context([
        'prompt' => 'Test',
        'output_variable' => 'result',
        'fallback_message' => 'Fallback por timeout',
    ]);

    $result = $executor->execute($context);

    expect($result->state)->toBe('continue')
        ->and($context->execution->fresh()->variables['custom']['result'])->toBe('Fallback por timeout');
});

// ---------------------------------------------------------------------------
// AI-07: 429 → fallback
// ---------------------------------------------------------------------------
test('AI-07: Rate limit produce fallback', function (): void {
    $fake = new FakeAIProvider;
    $fake->withException(new AIRateLimitException);
    $executor = make_executor($fake);
    $context = ai_context([
        'prompt' => 'Test',
        'output_variable' => 'result',
        'fallback_message' => 'Fallback por rate limit',
    ]);

    $result = $executor->execute($context);

    expect($result->state)->toBe('continue')
        ->and($context->execution->fresh()->variables['custom']['result'])->toBe('Fallback por rate limit');
});

// ---------------------------------------------------------------------------
// AI-08: provider error → fallback
// ---------------------------------------------------------------------------
test('AI-08: AIAuthFailedException produce fallback', function (): void {
    $fake = new FakeAIProvider;
    $fake->withException(new AIAuthFailedException);
    $executor = make_executor($fake);
    $context = ai_context([
        'prompt' => 'Test',
        'output_variable' => 'result',
        'fallback_message' => 'Fallback por auth',
    ]);

    $result = $executor->execute($context);

    expect($result->state)->toBe('continue')
        ->and($context->execution->fresh()->variables['custom']['result'])->toBe('Fallback por auth');
});

// ---------------------------------------------------------------------------
// AI-09: NO auto-send
// ---------------------------------------------------------------------------
test('AI-09: AI node NO envía mensajes, solo guarda en custom', function (): void {
    $fake = new FakeAIProvider;
    $fake->withResponse('Contenido AI');
    $executor = make_executor($fake);
    $context = ai_context(['prompt' => 'Genera', 'output_variable' => 'output']);

    $result = $executor->execute($context);

    $outboundCount = Message::query()
        ->where('conversation_id', $context->conversation->id)
        ->where('direction', 'outbound')
        ->count();

    expect($outboundCount)->toBe(0)
        ->and($result->state)->toBe('continue');
});

// ---------------------------------------------------------------------------
// AI-10: ejecución duplicada no duplica provider call
// ---------------------------------------------------------------------------
test('AI-10: second execution reuses cached output without new provider call', function (): void {
    $fake = new FakeAIProvider;
    $fake->withResponse('First result');
    $executor = make_executor($fake);
    $context = ai_context([
        'prompt' => 'Test',
        'output_variable' => 'cached_result',
    ]);

    $executor->execute($context);

    expect($fake->callCount())->toBe(1);

    $context->execution->refresh();
    $context->execution->logs()->create([
        'tenant_id' => $context->tenant->id,
        'node_id' => $context->node->id,
        'event' => 'ai_completed',
        'payload' => null,
        'sequence' => 1,
    ]);

    $fake->withResponse('Second result should not be used');

    $result = $executor->execute($context);

    expect($fake->callCount())->toBe(1)
        ->and($context->execution->fresh()->variables['custom']['cached_result'])->toBe('First result')
        ->and($result->state)->toBe('continue');
});

// ---------------------------------------------------------------------------
// AI-11: bot_paused no llama provider
// ---------------------------------------------------------------------------
test('AI-11: bot_paused check is defense-in-depth (provider not called if bot paused)', function (): void {
    $fake = new FakeAIProvider;
    $fake->withResponse('Should not execute');
    $executor = make_executor($fake);

    $context = ai_context(['prompt' => 'Test', 'output_variable' => 'x']);
    $context->conversation->forceFill(['bot_paused' => true])->save();
    $context->conversation->refresh();

    $result = $executor->execute($context);

    expect($fake->callCount())->toBe(0)
        ->and($result->state)->toBe('continue');
});

// ---------------------------------------------------------------------------
// AI-12: output control chars sanitizado
// ---------------------------------------------------------------------------
test('AI-12: Control characters in output are sanitized', function (): void {
    $fake = new FakeAIProvider;
    $fake->withResponse("Hello\x00\x01\x02World\x08\x0B");
    $executor = make_executor($fake);
    $context = ai_context(['prompt' => 'Test', 'output_variable' => 'clean']);

    $executor->execute($context);

    $output = $context->execution->fresh()->variables['custom']['clean'];
    expect($output)->toBe('HelloWorld');
});

// ---------------------------------------------------------------------------
// AI-13: output > límite tratado correctamente
// ---------------------------------------------------------------------------
test('AI-13: Output exceeding MAX_VALUE_LENGTH is truncated', function (): void {
    $fake = new FakeAIProvider;
    $fake->withResponse(str_repeat('A', 5000));
    $executor = make_executor($fake);
    $context = ai_context(['prompt' => 'Test', 'output_variable' => 'long_output']);

    $executor->execute($context);

    $output = $context->execution->fresh()->variables['custom']['long_output'];
    expect(mb_strlen($output))->toBeLessThanOrEqual(4096);
});

// ---------------------------------------------------------------------------
// AI-14: prompt/context no contiene secretos
// ---------------------------------------------------------------------------
test('AI-14: AIRequest prompt does not contain secrets or API keys', function (): void {
    $fakeApiKey = 'sk-test-fake-key-12345';
    config(['ai.providers.openai.api_key' => $fakeApiKey]);

    $fake = new FakeAIProvider;
    $fake->withResponse('OK');
    $executor = make_executor($fake);
    $context = ai_context([
        'prompt' => 'Responde al usuario',
        'system_prompt' => 'Eres un asistente',
        'output_variable' => 'result',
    ]);

    $executor->execute($context);

    $request = $fake->lastRequest();
    $fullPrompt = $request->prompt.($request->systemPrompt ?? '');

    expect($fullPrompt)->not->toContain('sk-')
        ->and($fullPrompt)->not->toContain($fakeApiKey)
        ->and($fullPrompt)->not->toContain($context->tenant->id);
});

// ---------------------------------------------------------------------------
// AI-15: AI provider recibe model/config según abstracción U1
// ---------------------------------------------------------------------------
test('AI-15: AIRequest uses default config from provider abstraction', function (): void {
    $fake = new FakeAIProvider;
    $fake->withResponse('OK');
    $executor = make_executor($fake);
    $context = ai_context(['prompt' => 'Test', 'output_variable' => 'x']);

    $executor->execute($context);

    $request = $fake->lastRequest();
    expect($request->temperature)->toBe(0.7)
        ->and($request->maxTokens)->toBe(500)
        ->and($request->model)->toBe('');
});
