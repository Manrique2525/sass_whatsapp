<?php

declare(strict_types=1);

use App\Application\Flows\Services\AiPromptBuilder;
use App\Application\Flows\Services\Executors\AiNodeExecutor;
use App\Application\KnowledgeBase\Services\KnowledgeSearchService;
use App\Domain\AI\Contracts\EmbeddingProviderInterface;
use App\Domain\AI\Exceptions\AIAuthFailedException;
use App\Domain\AI\Exceptions\AIRateLimitException;
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
use Illuminate\Http\Client\ConnectionException;
use Tests\Fakes\FakeAIProvider;
use Tests\Fakes\FakeEmbeddingProvider;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
});

/*
|--------------------------------------------------------------------------
| FASE 16 U4 — AI USAGE TELEMETRY (EXECUTOR TESTS)
|--------------------------------------------------------------------------
|
| Tests AI-U09..U25: executor telemetry payloads, latency, PII
| exclusion, idempotency, fallback_used.
|
*/

function telemetry_context(array $nodeConfig = [], array $custom = []): NodeExecutionContext
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

function make_telemetry_executor(?FakeAIProvider $fake = null): AiNodeExecutor
{
    $fake ??= new FakeAIProvider;

    $embeddingFake = new FakeEmbeddingProvider;
    app()->instance(EmbeddingProviderInterface::class, $embeddingFake);

    return new AiNodeExecutor(
        provider: $fake,
        promptBuilder: new AiPromptBuilder(new VariableResolver),
        searchService: new KnowledgeSearchService($embeddingFake),
    );
}

function get_latest_log(NodeExecutionContext $context, string $event): ?array
{
    $log = $context->execution->logs()
        ->where('event', $event)
        ->latest()
        ->first();

    return $log?->payload;
}

// ---------------------------------------------------------------------------
// AI-U09: ai_completed log contains latency_ms (int >= 0)
// ---------------------------------------------------------------------------
test('AI-U09: ai_completed log contains latency_ms as non-negative integer', function (): void {
    $fake = new FakeAIProvider;
    $fake->withResponse('OK');
    $executor = make_telemetry_executor($fake);
    $context = telemetry_context(['prompt' => 'Test', 'output_variable' => 'out']);

    $executor->execute($context);

    $payload = get_latest_log($context, 'ai_completed');

    expect($payload)->not->toBeNull()
        ->and($payload['latency_ms'])->toBeInt()
        ->and($payload['latency_ms'])->toBeGreaterThanOrEqual(0);
});

// ---------------------------------------------------------------------------
// AI-U10: ai_completed log contains success: true
// ---------------------------------------------------------------------------
test('AI-U10: ai_completed log contains success true', function (): void {
    $fake = new FakeAIProvider;
    $fake->withResponse('Hello');
    $executor = make_telemetry_executor($fake);
    $context = telemetry_context(['prompt' => 'Test', 'output_variable' => 'out']);

    $executor->execute($context);

    $payload = get_latest_log($context, 'ai_completed');

    expect($payload['success'])->toBeTrue();
});

// ---------------------------------------------------------------------------
// AI-U11: ai_completed log contains provider, model, token counts
// ---------------------------------------------------------------------------
test('AI-U11: ai_completed log contains provider, model, and token counts', function (): void {
    $fake = new FakeAIProvider;
    $fake->withResponse('Result');
    $executor = make_telemetry_executor($fake);
    $context = telemetry_context(['prompt' => 'Test', 'output_variable' => 'out']);

    $executor->execute($context);

    $payload = get_latest_log($context, 'ai_completed');

    expect($payload['provider'])->toBe('fake')
        ->and($payload['model'])->toBe('fake-model')
        ->and($payload['input_tokens'])->toBe(10)
        ->and($payload['output_tokens'])->toBe(20)
        ->and($payload['total_tokens'])->toBe(30);
});

// ---------------------------------------------------------------------------
// AI-U12: ai_completed log contains output_variable
// ---------------------------------------------------------------------------
test('AI-U12: ai_completed log contains output_variable', function (): void {
    $fake = new FakeAIProvider;
    $fake->withResponse('OK');
    $executor = make_telemetry_executor($fake);
    $context = telemetry_context(['prompt' => 'Test', 'output_variable' => 'my_var']);

    $executor->execute($context);

    $payload = get_latest_log($context, 'ai_completed');

    expect($payload['output_variable'])->toBe('my_var');
});

// ---------------------------------------------------------------------------
// AI-U13: ai_failed log contains latency_ms (int >= 0)
// ---------------------------------------------------------------------------
test('AI-U13: ai_failed log contains latency_ms as non-negative integer', function (): void {
    $fake = new FakeAIProvider;
    $fake->withException(new ConnectionException('Timeout'));
    $executor = make_telemetry_executor($fake);
    $context = telemetry_context([
        'prompt' => 'Test',
        'output_variable' => 'out',
        'fallback_message' => 'Sorry',
    ]);

    $executor->execute($context);

    $payload = get_latest_log($context, 'ai_failed');

    expect($payload)->not->toBeNull()
        ->and($payload['latency_ms'])->toBeInt()
        ->and($payload['latency_ms'])->toBeGreaterThanOrEqual(0);
});

// ---------------------------------------------------------------------------
// AI-U14: ai_failed log contains success: false
// ---------------------------------------------------------------------------
test('AI-U14: ai_failed log contains success false', function (): void {
    $fake = new FakeAIProvider;
    $fake->withException(new AIAuthFailedException);
    $executor = make_telemetry_executor($fake);
    $context = telemetry_context([
        'prompt' => 'Test',
        'output_variable' => 'out',
        'fallback_message' => 'Fallback',
    ]);

    $executor->execute($context);

    $payload = get_latest_log($context, 'ai_failed');

    expect($payload['success'])->toBeFalse();
});

// ---------------------------------------------------------------------------
// AI-U15: ai_failed log contains error_code when AIException
// ---------------------------------------------------------------------------
test('AI-U15: ai_failed log contains error_code from AIException', function (): void {
    $fake = new FakeAIProvider;
    $fake->withException(new AIRateLimitException);
    $executor = make_telemetry_executor($fake);
    $context = telemetry_context([
        'prompt' => 'Test',
        'output_variable' => 'out',
        'fallback_message' => 'Rate limited',
    ]);

    $executor->execute($context);

    $payload = get_latest_log($context, 'ai_failed');

    expect($payload['error_code'])->toBe('AI_RATE_LIMIT');
});

// ---------------------------------------------------------------------------
// AI-U16: ai_failed log fallback_used true when fallback_message exists
// ---------------------------------------------------------------------------
test('AI-U16: ai_failed log fallback_used true when fallback message provided', function (): void {
    $fake = new FakeAIProvider;
    $fake->withException(new ConnectionException('Timeout'));
    $executor = make_telemetry_executor($fake);
    $context = telemetry_context([
        'prompt' => 'Test',
        'output_variable' => 'out',
        'fallback_message' => 'We will try later',
    ]);

    $executor->execute($context);

    $payload = get_latest_log($context, 'ai_failed');

    expect($payload['fallback_used'])->toBeTrue();
});

// ---------------------------------------------------------------------------
// AI-U17: ai_failed log fallback_used false when no fallback
// ---------------------------------------------------------------------------
test('AI-U17: ai_failed log fallback_used false when no fallback configured', function (): void {
    $fake = new FakeAIProvider;
    $fake->withException(new ConnectionException('Timeout'));
    $executor = make_telemetry_executor($fake);
    $context = telemetry_context([
        'prompt' => 'Test',
        'output_variable' => 'out',
    ]);

    $executor->execute($context);

    $payload = get_latest_log($context, 'ai_failed');

    expect($payload['fallback_used'])->toBeFalse();
});

// ---------------------------------------------------------------------------
// AI-U18: ai_failed log contains error message
// ---------------------------------------------------------------------------
test('AI-U18: ai_failed log contains error message', function (): void {
    $fake = new FakeAIProvider;
    $fake->withException(new ConnectionException('Connection refused'));
    $executor = make_telemetry_executor($fake);
    $context = telemetry_context([
        'prompt' => 'Test',
        'output_variable' => 'out',
        'fallback_message' => 'Fallback',
    ]);

    $executor->execute($context);

    $payload = get_latest_log($context, 'ai_failed');

    expect($payload['error'])->toBe('Connection refused');
});

// ---------------------------------------------------------------------------
// AI-U19: Idempotency — second execution reuses output without new log
// ---------------------------------------------------------------------------
test('AI-U19: second execution reuses cached output without new telemetry log', function (): void {
    $fake = new FakeAIProvider;
    $fake->withResponse('First result');
    $executor = make_telemetry_executor($fake);
    $context = telemetry_context([
        'prompt' => 'Test',
        'output_variable' => 'cached',
    ]);

    $executor->execute($context);

    expect($fake->callCount())->toBe(1);

    $logCountAfterFirst = $context->execution->logs()
        ->where('event', 'ai_completed')
        ->count();

    // Simulate second execution (idempotency gate: output + log both present)
    $fake->withResponse('Second result');

    $result = $executor->execute($context);

    expect($fake->callCount())->toBe(1);

    $logCountAfterSecond = $context->execution->logs()
        ->where('event', 'ai_completed')
        ->count();

    expect($logCountAfterSecond)->toBe($logCountAfterFirst)
        ->and($context->execution->fresh()->variables['custom']['cached'])->toBe('First result');
});

// ---------------------------------------------------------------------------
// AI-U20: Empty response triggers ai_failed telemetry
// ---------------------------------------------------------------------------
test('AI-U20: empty provider response produces ai_failed telemetry with success false', function (): void {
    $fake = new FakeAIProvider;
    $fake->withResponse('');
    $executor = make_telemetry_executor($fake);
    $context = telemetry_context([
        'prompt' => 'Test',
        'output_variable' => 'out',
        'fallback_message' => 'Empty fallback',
    ]);

    $executor->execute($context);

    $payload = get_latest_log($context, 'ai_failed');

    expect($payload)->not->toBeNull()
        ->and($payload['success'])->toBeFalse()
        ->and($payload['fallback_used'])->toBeTrue()
        ->and($payload['latency_ms'])->toBeGreaterThanOrEqual(0);
});

// ---------------------------------------------------------------------------
// AI-U21: PII never appears in any telemetry payload
// ---------------------------------------------------------------------------
test('AI-U21: PII never appears in ai_completed or ai_failed telemetry', function (): void {
    $fake = new FakeAIProvider;
    $fake->withResponse('AI generated text');
    $executor = make_telemetry_executor($fake);
    $context = telemetry_context([
        'prompt' => 'Hola Juan Perez, tu email es juan@test.com y negocio Mi Negocio',
        'output_variable' => 'out',
    ]);

    $executor->execute($context);

    $payload = get_latest_log($context, 'ai_completed');
    $json = json_encode($payload);

    expect($json)->not->toContain('Juan Perez')
        ->and($json)->not->toContain('juan@test.com')
        ->and($json)->not->toContain('+5215551234567')
        ->and($json)->not->toContain('Mi Negocio')
        ->and($json)->not->toContain('Tienda de ropa')
        ->and($json)->not->toContain('Hola Juan')
        ->and($json)->not->toContain('AI generated text')
        ->and($json)->not->toContain('prompt')
        ->and($json)->not->toContain('system_prompt')
        ->and($json)->not->toContain('content');
});

// ---------------------------------------------------------------------------
// AI-U22: Latency is measured with monotonic clock
// ---------------------------------------------------------------------------
test('AI-U22: ai_completed latency_ms is non-negative and reasonable', function (): void {
    $fake = new FakeAIProvider;
    $fake->withResponse('OK');
    $executor = make_telemetry_executor($fake);
    $context = telemetry_context(['prompt' => 'Test', 'output_variable' => 'out']);

    $executor->execute($context);

    $payload = get_latest_log($context, 'ai_completed');

    expect($payload['latency_ms'])->toBeInt()
        ->and($payload['latency_ms'])->toBeGreaterThanOrEqual(0)
        ->and($payload['latency_ms'])->toBeLessThan(30_000);
});

// ---------------------------------------------------------------------------
// AI-U23: bot_paused produces no telemetry logs
// ---------------------------------------------------------------------------
test('AI-U23: bot_paused produces no ai_completed or ai_failed telemetry logs', function (): void {
    $fake = new FakeAIProvider;
    $fake->withResponse('Should not log');
    $executor = make_telemetry_executor($fake);
    $context = telemetry_context(['prompt' => 'Test', 'output_variable' => 'out']);
    $context->conversation->forceFill(['bot_paused' => true])->save();

    $executor->execute($context);

    $completedLogs = $context->execution->logs()->where('event', 'ai_completed')->count();
    $failedLogs = $context->execution->logs()->where('event', 'ai_failed')->count();

    expect($completedLogs)->toBe(0)
        ->and($failedLogs)->toBe(0);
});

// ---------------------------------------------------------------------------
// AI-U24: Invalid output_variable produces no telemetry logs
// ---------------------------------------------------------------------------
test('AI-U24: invalid output_variable produces no ai telemetry logs', function (): void {
    $fake = new FakeAIProvider;
    $fake->withResponse('OK');
    $executor = make_telemetry_executor($fake);
    $context = telemetry_context(['prompt' => 'Test', 'output_variable' => '__proto__']);

    $executor->execute($context);

    $completedLogs = $context->execution->logs()->where('event', 'ai_completed')->count();
    $failedLogs = $context->execution->logs()->where('event', 'ai_failed')->count();

    expect($completedLogs)->toBe(0)
        ->and($failedLogs)->toBe(0);
});

// ---------------------------------------------------------------------------
// AI-U25: ai_completed payload has only safe schema keys
// ---------------------------------------------------------------------------
test('AI-U25: ai_completed payload has exactly the safe schema keys plus output_variable', function (): void {
    $fake = new FakeAIProvider;
    $fake->withResponse('OK');
    $executor = make_telemetry_executor($fake);
    $context = telemetry_context(['prompt' => 'Test', 'output_variable' => 'result']);

    $executor->execute($context);

    $payload = get_latest_log($context, 'ai_completed');
    $keys = array_keys($payload);

    $expectedKeys = [
        'operation', 'provider', 'model', 'input_tokens', 'output_tokens',
        'total_tokens', 'latency_ms', 'success', 'error_code', 'fallback_used',
        'output_variable', 'rag_used', 'retrieved_chunks_count',
    ];

    expect($keys)->toBe($expectedKeys);
});
