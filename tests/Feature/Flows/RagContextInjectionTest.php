<?php

declare(strict_types=1);

use App\Application\Flows\Services\AiPromptBuilder;
use App\Application\Flows\Services\Executors\AiNodeExecutor;
use App\Domain\AI\Exceptions\AIAuthFailedException;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Flows\Enums\FlowNodeType;
use App\Domain\Flows\Models\Chatbot;
use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Models\FlowExecution;
use App\Domain\Flows\Models\FlowNode;
use App\Domain\Flows\Services\VariableResolver;
use App\Domain\Flows\ValueObjects\NodeExecutionContext;
use App\Domain\Tenants\Models\Tenant;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeAIProvider;
use Tests\Fakes\FakeKnowledgeSearchService;
use Tests\Fakes\FakeUsageGuard;

uses(RefreshDatabase::class);
uses()->group('RAG-AI');

afterEach(function (): void {
    TenantContext::clear();
});

/*
|--------------------------------------------------------------------------
| FASE 17 U3.4 — RAG Context Injection Feature Tests
|--------------------------------------------------------------------------
|
| RAG-AI-01..15: integración KnowledgeSearchService → AiNodeExecutor → AiPromptBuilder
|
*/

function rag_ai_context(
    array $nodeConfig = [],
    array $custom = [],
): NodeExecutionContext {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    $contact = Contact::query()->create([
        'name' => 'Maria Lopez',
        'phone' => '+5215559876543',
        'email' => 'maria@test.com',
    ]);

    $business = $tenant->businessProfile()->create([
        'name' => 'Tech Solutions',
        'description' => 'Empresa de tecnología',
        'category' => 'technology',
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

function rag_executor(
    ?FakeAIProvider $aiFake = null,
    ?FakeKnowledgeSearchService $searchFake = null,
): array {
    $aiFake ??= new FakeAIProvider;
    $searchFake ??= new FakeKnowledgeSearchService;

    $executor = new AiNodeExecutor(
        provider: $aiFake,
        promptBuilder: new AiPromptBuilder(new VariableResolver),
        searchService: $searchFake,
        usageGuard: new FakeUsageGuard,
    );

    return ['executor' => $executor, 'ai' => $aiFake, 'search' => $searchFake];
}

// ---------------------------------------------------------------------------
// RAG-AI-01: AI node without KB works unchanged
// ---------------------------------------------------------------------------
test('RAG-AI-01: AI node without knowledge_base_id works unchanged', function (): void {
    $fakes = rag_executor();
    $context = rag_ai_context([
        'prompt' => 'Hola, ¿qué puedes hacer?',
        'output_variable' => 'respuesta',
    ]);

    $fakes['executor']->execute($context);

    expect($fakes['search']->callCount())->toBe(0)
        ->and($fakes['ai']->callCount())->toBe(1);
});

// ---------------------------------------------------------------------------
// RAG-AI-02: AI node with KB retrieves chunks
// ---------------------------------------------------------------------------
test('RAG-AI-02: AI node with knowledge_base_id calls KnowledgeSearchService', function (): void {
    $searchFake = new FakeKnowledgeSearchService;
    $searchFake->withResult(FakeKnowledgeSearchService::chunks([
        'Nuestro horario es de 9am a 6pm.',
        'Ofrecemos soporte 24/7.',
    ]));
    $fakes = rag_executor(searchFake: $searchFake);

    $context = rag_ai_context([
        'prompt' => '¿Cuál es su horario?',
        'output_variable' => 'respuesta',
        'knowledge_base_id' => '550e8400-e29b-41d4-a716-446655440000',
    ]);

    $fakes['executor']->execute($context);

    expect($fakes['search']->callCount())->toBe(1)
        ->and($fakes['ai']->callCount())->toBe(1)
        ->and($fakes['search']->lastCall()['knowledgeBaseId'])->toBe('550e8400-e29b-41d4-a716-446655440000');
});

// ---------------------------------------------------------------------------
// RAG-AI-03: Retrieved chunks included in prompt
// ---------------------------------------------------------------------------
test('RAG-AI-03: Retrieved chunks appear in the AI request prompt', function (): void {
    $searchFake = new FakeKnowledgeSearchService;
    $searchFake->withResult(FakeKnowledgeSearchService::chunks([
        'Nuestro horario es de 9am a 6pm de lunes a viernes.',
    ]));
    $fakes = rag_executor(searchFake: $searchFake);

    $context = rag_ai_context([
        'prompt' => '¿Cuál es su horario?',
        'output_variable' => 'respuesta',
        'knowledge_base_id' => '550e8400-e29b-41d4-a716-446655440000',
    ]);

    $fakes['executor']->execute($context);

    $prompt = $fakes['ai']->lastRequest()->prompt;

    expect($prompt)->toContain('KNOWLEDGE CONTEXT (UNTRUSTED DATA)')
        ->and($prompt)->toContain('Nuestro horario es de 9am a 6pm')
        ->and($prompt)->toContain('END KNOWLEDGE CONTEXT');
});

// ---------------------------------------------------------------------------
// RAG-AI-04: Ordering preserved
// ---------------------------------------------------------------------------
test('RAG-AI-04: Multiple chunks maintain their order in the prompt', function (): void {
    $searchFake = new FakeKnowledgeSearchService;
    $searchFake->withResult(FakeKnowledgeSearchService::chunks([
        'Primera pieza de información.',
        'Segunda pieza de información.',
        'Tercera pieza de información.',
    ]));
    $fakes = rag_executor(searchFake: $searchFake);

    $context = rag_ai_context([
        'prompt' => 'Cuéntame todo.',
        'output_variable' => 'respuesta',
        'knowledge_base_id' => '550e8400-e29b-41d4-a716-446655440000',
    ]);

    $fakes['executor']->execute($context);

    $prompt = $fakes['ai']->lastRequest()->prompt;
    $pos1 = strpos($prompt, 'Primera pieza');
    $pos2 = strpos($prompt, 'Segunda pieza');
    $pos3 = strpos($prompt, 'Tercera pieza');

    expect($pos1)->not->toBeFalse()
        ->and($pos2)->not->toBeFalse()
        ->and($pos3)->not->toBeFalse()
        ->and($pos1)->toBeLessThan($pos2)
        ->and($pos2)->toBeLessThan($pos3);
});

// ---------------------------------------------------------------------------
// RAG-AI-05: Empty retrieval continues
// ---------------------------------------------------------------------------
test('RAG-AI-05: Empty retrieval continues without knowledge context', function (): void {
    $searchFake = new FakeKnowledgeSearchService;
    $searchFake->withEmptyResult();
    $fakes = rag_executor(searchFake: $searchFake);

    $context = rag_ai_context([
        'prompt' => '¿Qué sabes?',
        'output_variable' => 'respuesta',
        'knowledge_base_id' => '550e8400-e29b-41d4-a716-446655440000',
    ]);

    $fakes['executor']->execute($context);

    expect($fakes['ai']->callCount())->toBe(1)
        ->and($fakes['ai']->lastRequest()->prompt)->not->toContain('KNOWLEDGE CONTEXT');
});

// ---------------------------------------------------------------------------
// RAG-AI-06: Deleted KB handled
// ---------------------------------------------------------------------------
test('RAG-AI-06: Deleted knowledge base handled gracefully (no crash)', function (): void {
    $searchFake = new FakeKnowledgeSearchService;
    $searchFake->withEmptyResult();
    $fakes = rag_executor(searchFake: $searchFake);

    $context = rag_ai_context([
        'prompt' => 'Pregunta',
        'output_variable' => 'respuesta',
        'knowledge_base_id' => '00000000-0000-0000-0000-000000000099',
    ]);

    $fakes['executor']->execute($context);

    expect($fakes['ai']->callCount())->toBe(1);
});

// ---------------------------------------------------------------------------
// RAG-AI-07: Invalid KB ID handled
// ---------------------------------------------------------------------------
test('RAG-AI-07: Invalid knowledge_base_id (not UUID) does not crash execution', function (): void {
    $searchFake = new FakeKnowledgeSearchService;
    $searchFake->withEmptyResult();
    $fakes = rag_executor(searchFake: $searchFake);

    $context = rag_ai_context([
        'prompt' => 'Pregunta',
        'output_variable' => 'respuesta',
        'knowledge_base_id' => 'not-a-uuid',
    ]);

    $fakes['executor']->execute($context);

    // Search IS called (no UUID validation at executor level), but execution completes
    expect($fakes['search']->callCount())->toBe(1)
        ->and($fakes['ai']->callCount())->toBe(1);
});

// ---------------------------------------------------------------------------
// RAG-AI-08: Cross-tenant KB blocked
// ---------------------------------------------------------------------------
test('RAG-AI-08: Cross-tenant KB search uses correct tenant_id', function (): void {
    $searchFake = new FakeKnowledgeSearchService;
    $searchFake->withEmptyResult();
    $fakes = rag_executor(searchFake: $searchFake);

    $context = rag_ai_context([
        'prompt' => 'Pregunta',
        'output_variable' => 'respuesta',
        'knowledge_base_id' => '550e8400-e29b-41d4-a716-446655440000',
    ]);

    $fakes['executor']->execute($context);

    $lastCall = $fakes['search']->lastCall();
    expect($lastCall)->not->toBeNull()
        ->and($lastCall['tenantId'])->toBe($context->tenant->id);
});

// ---------------------------------------------------------------------------
// RAG-AI-09: bot_paused = no search, no AI
// ---------------------------------------------------------------------------
test('RAG-AI-09: bot_paused prevents both search and AI provider call', function (): void {
    $searchFake = new FakeKnowledgeSearchService;
    $fakes = rag_executor(searchFake: $searchFake);

    $context = rag_ai_context([
        'prompt' => 'Pregunta',
        'output_variable' => 'respuesta',
        'knowledge_base_id' => '550e8400-e29b-41d4-a716-446655440000',
    ]);

    // Force bot_paused
    $context->conversation->forceFill(['bot_paused' => true])->save();

    $result = $fakes['executor']->execute($context);

    expect($fakes['search']->callCount())->toBe(0)
        ->and($fakes['ai']->callCount())->toBe(0);
});

// ---------------------------------------------------------------------------
// RAG-AI-10: Idempotent replay = no search, no AI
// ---------------------------------------------------------------------------
test('RAG-AI-10: Idempotent replay does not trigger search or AI', function (): void {
    $searchFake = new FakeKnowledgeSearchService;
    $fakes = rag_executor(searchFake: $searchFake);

    $context = rag_ai_context(
        [
            'prompt' => 'Pregunta',
            'output_variable' => 'respuesta',
            'knowledge_base_id' => '550e8400-e29b-41d4-a716-446655440000',
        ],
        ['respuesta' => 'cached response'],
    );

    // Simulate existing ai_completed log
    $context->execution->logs()->create([
        'tenant_id' => $context->tenant->id,
        'node_id' => $context->node->id,
        'event' => 'ai_completed',
        'payload' => ['operation' => 'chat'],
        'sequence' => 1,
    ]);

    $fakes['executor']->execute($context);

    expect($fakes['search']->callCount())->toBe(0)
        ->and($fakes['ai']->callCount())->toBe(0);
});

// ---------------------------------------------------------------------------
// RAG-AI-11: Provider fallback still works with RAG
// ---------------------------------------------------------------------------
test('RAG-AI-11: Provider failure triggers fallback even with RAG enabled', function (): void {
    $searchFake = new FakeKnowledgeSearchService;
    $searchFake->withResult(FakeKnowledgeSearchService::chunks(['Info útil.']));

    $aiFake = new FakeAIProvider;
    $aiFake->withException(new AIAuthFailedException('Invalid key'));

    $executor = new AiNodeExecutor(
        provider: $aiFake,
        promptBuilder: new AiPromptBuilder(new VariableResolver),
        searchService: $searchFake,
        usageGuard: new FakeUsageGuard,
    );

    $context = rag_ai_context([
        'prompt' => 'Pregunta',
        'output_variable' => 'respuesta',
        'knowledge_base_id' => '550e8400-e29b-41d4-a716-446655440000',
        'fallback_message' => 'Lo siento, no puedo responder ahora.',
    ]);

    $result = $executor->execute($context);

    expect($aiFake->callCount())->toBe(1);

    $output = $context->execution->fresh()->variables['custom']['respuesta'] ?? null;
    expect($output)->toBe('Lo siento, no puedo responder ahora.');
});

// ---------------------------------------------------------------------------
// RAG-AI-12: Variables resolved before search
// ---------------------------------------------------------------------------
test('RAG-AI-12: Search query contains resolved variables', function (): void {
    $searchFake = new FakeKnowledgeSearchService;
    $searchFake->withEmptyResult();
    $fakes = rag_executor(searchFake: $searchFake);

    $context = rag_ai_context(
        [
            'prompt' => '¿Qué sabes sobre {{custom.topic}}?',
            'output_variable' => 'respuesta',
            'knowledge_base_id' => '550e8400-e29b-41d4-a716-446655440000',
        ],
        ['topic' => 'nuestros productos'],
    );

    $fakes['executor']->execute($context);

    $lastCall = $fakes['search']->lastCall();
    expect($lastCall['query'])->toContain('nuestros productos');
});

// ---------------------------------------------------------------------------
// RAG-AI-13: Max context preserved
// ---------------------------------------------------------------------------
test('RAG-AI-13: Knowledge context does not overflow prompt max length', function (): void {
    $longContent = str_repeat('Esta es información importante. ', 200);
    $searchFake = new FakeKnowledgeSearchService;
    $searchFake->withResult(FakeKnowledgeSearchService::chunks([$longContent]));

    $fakes = rag_executor(searchFake: $searchFake);

    $context = rag_ai_context([
        'prompt' => 'Pregunta corta',
        'output_variable' => 'respuesta',
        'knowledge_base_id' => '550e8400-e29b-41d4-a716-446655440000',
    ]);

    $fakes['executor']->execute($context);

    $prompt = $fakes['ai']->lastRequest()->prompt;
    expect(mb_strlen($prompt))->toBeLessThanOrEqual(8000);
});

// ---------------------------------------------------------------------------
// RAG-AI-14: No scores/vectors in prompt
// ---------------------------------------------------------------------------
test('RAG-AI-14: Similarity scores and vectors never appear in prompt', function (): void {
    $searchFake = new FakeKnowledgeSearchService;
    $searchFake->withResult(FakeKnowledgeSearchService::chunks(['Contenido secreto.']));
    $fakes = rag_executor(searchFake: $searchFake);

    $context = rag_ai_context([
        'prompt' => 'Pregunta',
        'output_variable' => 'respuesta',
        'knowledge_base_id' => '550e8400-e29b-41d4-a716-446655440000',
    ]);

    $fakes['executor']->execute($context);

    $prompt = $fakes['ai']->lastRequest()->prompt;

    expect($prompt)->not->toContain('0.9')
        ->and($prompt)->not->toContain('similarity')
        ->and($prompt)->not->toContain('::vector')
        ->and($prompt)->not->toContain('[');
});

// ---------------------------------------------------------------------------
// RAG-AI-15: Output variable behavior unchanged
// ---------------------------------------------------------------------------
test('RAG-AI-15: Output variable persists correctly with RAG enabled', function (): void {
    $searchFake = new FakeKnowledgeSearchService;
    $searchFake->withResult(FakeKnowledgeSearchService::chunks(['Info']));
    $fakes = rag_executor(searchFake: $searchFake);

    $context = rag_ai_context([
        'prompt' => 'Pregunta',
        'output_variable' => 'rag_answer',
        'knowledge_base_id' => '550e8400-e29b-41d4-a716-446655440000',
    ]);

    $fakes['executor']->execute($context);

    $output = $context->execution->fresh()->variables['custom']['rag_answer'] ?? null;
    expect($output)->not->toBeNull()
        ->and($output)->toBe('Fake AI response');
});
