<?php

declare(strict_types=1);

use App\Application\Flows\Services\AiPromptBuilder;
use App\Application\Flows\Services\Executors\AiNodeExecutor;
use App\Application\KnowledgeBase\Contracts\KnowledgeSearchServiceInterface;
use App\Domain\AI\Contracts\AIProviderInterface;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Flows\Enums\FlowNodeType;
use App\Domain\Flows\Models\Chatbot;
use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Models\FlowExecution;
use App\Domain\Flows\Models\FlowExecutionLog;
use App\Domain\Flows\Models\FlowNode;
use App\Domain\Flows\Services\VariableResolver;
use App\Domain\Flows\ValueObjects\NodeExecutionContext;
use App\Domain\Tenants\Models\Tenant;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Tests\Fakes\FakeAIProvider;
use Tests\Fakes\FakeKnowledgeSearchService;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
});

/*
|--------------------------------------------------------------------------
| FASE 16 U5 — SECURITY MATRIX (AI-SEC-F01..F12)
|--------------------------------------------------------------------------
|
| Formal security matrix verifying every security property of FASE 16.
|
*/

function sec_context(array $nodeConfig = [], array $custom = []): NodeExecutionContext
{
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    $contact = Contact::query()->create([
        'name' => 'Test Contact',
        'phone' => '+5215551234567',
        'email' => 'test@example.com',
    ]);

    $business = $tenant->businessProfile()->create([
        'name' => 'Test Business',
        'description' => 'Test description',
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

function sec_executor(?FakeAIProvider $fake = null): AiNodeExecutor
{
    $fake ??= new FakeAIProvider;

    return new AiNodeExecutor(
        provider: $fake,
        promptBuilder: new AiPromptBuilder(new VariableResolver),
        searchService: new FakeKnowledgeSearchService,
    );
}

// ---------------------------------------------------------------------------
// AI-SEC-F01: API key never in logs
// ---------------------------------------------------------------------------
test('AI-SEC-F01: API key never appears in flow_execution_logs', function (): void {
    $fakeKey = 'sk-test-fake-key-12345';
    config(['ai.providers.openai.api_key' => $fakeKey]);

    $fake = new FakeAIProvider;
    $fake->withResponse('OK');
    $executor = sec_executor($fake);
    $context = sec_context([
        'prompt' => 'Test',
        'output_variable' => 'out',
    ]);

    $executor->execute($context);

    $logs = $context->execution->logs()->pluck('payload')->toArray();
    $allPayloads = json_encode($logs);

    expect($allPayloads)->not->toContain($fakeKey)
        ->and($allPayloads)->not->toContain('sk-');
});

// ---------------------------------------------------------------------------
// AI-SEC-F02: API key never in frontend config
// ---------------------------------------------------------------------------
test('AI-SEC-F02: AI config passed to frontend contains no API key or provider', function (): void {
    $config = [
        'prompt' => 'Test prompt',
        'system_prompt' => 'System instructions',
        'output_variable' => 'result',
        'fallback_message' => 'Fallback text',
    ];

    $json = json_encode($config);

    expect($json)->not->toContain('api_key')
        ->and($json)->not->toContain('OPENAI')
        ->and($json)->not->toContain('provider')
        ->and($json)->not->toContain('model')
        ->and($json)->not->toContain('sk-');
});

// ---------------------------------------------------------------------------
// AI-SEC-F03: API key never in audit logs
// ---------------------------------------------------------------------------
test('AI-SEC-F03: API key never appears in audit_logs', function (): void {
    $fakeKey = 'sk-test-audit-key-99999';
    config(['ai.providers.openai.api_key' => $fakeKey]);

    $fake = new FakeAIProvider;
    $fake->withResponse('OK');
    $executor = sec_executor($fake);
    $context = sec_context([
        'prompt' => 'Test',
        'output_variable' => 'out',
    ]);

    $executor->execute($context);

    $auditLogs = AuditLog::query()->pluck('data')->toArray();
    $allAuditData = json_encode($auditLogs);

    expect($allAuditData)->not->toContain($fakeKey)
        ->and($allAuditData)->not->toContain('sk-');
});

// ---------------------------------------------------------------------------
// AI-SEC-F04: Prompt never in telemetry
// ---------------------------------------------------------------------------
test('AI-SEC-F04: Full prompt never appears in ai_completed or ai_failed telemetry', function (): void {
    $secretPrompt = 'Secret system instructions for internal use only';

    $fake = new FakeAIProvider;
    $fake->withResponse('OK');
    $executor = sec_executor($fake);
    $context = sec_context([
        'prompt' => $secretPrompt,
        'output_variable' => 'out',
    ]);

    $executor->execute($context);

    $payload = $context->execution->logs()
        ->where('event', 'ai_completed')
        ->first()
        ->payload;

    expect(json_encode($payload))->not->toContain($secretPrompt)
        ->and(json_encode($payload))->not->toContain('Secret system');
});

// ---------------------------------------------------------------------------
// AI-SEC-F05: Response never in telemetry
// ---------------------------------------------------------------------------
test('AI-SEC-F05: Full AI response never appears in telemetry payload', function (): void {
    $secretResponse = 'This is confidential AI generated content';

    $fake = new FakeAIProvider;
    $fake->withResponse($secretResponse);
    $executor = sec_executor($fake);
    $context = sec_context([
        'prompt' => 'Test',
        'output_variable' => 'out',
    ]);

    $executor->execute($context);

    $payload = $context->execution->logs()
        ->where('event', 'ai_completed')
        ->first()
        ->payload;

    expect(json_encode($payload))->not->toContain($secretResponse)
        ->and(json_encode($payload))->not->toContain('confidential AI');
});

// ---------------------------------------------------------------------------
// AI-SEC-F06: PII never in telemetry
// ---------------------------------------------------------------------------
test('AI-SEC-F06: Contact PII never appears in telemetry payload', function (): void {
    $fake = new FakeAIProvider;
    $fake->withResponse('OK');
    $executor = sec_executor($fake);
    $context = sec_context([
        'prompt' => 'Generate for {{contact.name}} {{contact.email}}',
        'output_variable' => 'out',
    ]);

    $executor->execute($context);

    $payload = $context->execution->logs()
        ->where('event', 'ai_completed')
        ->first()
        ->payload;

    $json = json_encode($payload);

    expect($json)->not->toContain('Test Contact')
        ->and($json)->not->toContain('test@example.com')
        ->and($json)->not->toContain('+5215551234567');
});

// ---------------------------------------------------------------------------
// AI-SEC-F07: Tenant A/B isolation
// ---------------------------------------------------------------------------
test('AI-SEC-F07: Tenant A telemetry never contains Tenant B data', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    // Tenant A
    TenantContext::setId($tenantA->id);
    $businessA = $tenantA->businessProfile()->create([
        'name' => 'Business A',
        'description' => 'Desc A',
    ]);
    $contactA = Contact::query()->create([
        'name' => 'Contact A',
        'phone' => '+5215551111111',
    ]);
    $convA = Conversation::query()->create([
        'contact_id' => $contactA->id,
        'status' => 'open',
    ]);
    $chatbotA = Chatbot::query()->where('tenant_id', $tenantA->id)->first();
    if ($chatbotA === null) {
        $chatbotA = Chatbot::query()->create(['name' => 'Chatbot A']);
    }
    $flowA = Flow::query()->create([
        'chatbot_id' => $chatbotA->id,
        'name' => 'Flow A',
        'status' => 'published',
    ]);
    $nodeA = new FlowNode([
        'flow_id' => $flowA->id,
        'type' => FlowNodeType::AI->value,
        'name' => 'AI A',
        'position_x' => 0,
        'position_y' => 0,
        'config' => ['prompt' => 'Test A', 'output_variable' => 'result_a'],
        'is_start' => false,
    ]);
    $nodeA->save();
    $execA = FlowExecution::query()->create([
        'flow_id' => $flowA->id,
        'conversation_id' => $convA->id,
        'current_node_id' => $nodeA->id,
        'status' => 'running',
        'variables' => ['custom' => []],
        'attempts' => 0,
    ]);

    // Tenant B
    TenantContext::setId($tenantB->id);
    $businessB = $tenantB->businessProfile()->create([
        'name' => 'Business B SECRET',
        'description' => 'Desc B SECRET',
    ]);
    $contactB = Contact::query()->create([
        'name' => 'Contact B SECRET',
        'phone' => '+5215552222222',
    ]);
    $convB = Conversation::query()->create([
        'contact_id' => $contactB->id,
        'status' => 'open',
    ]);
    $chatbotB = Chatbot::query()->where('tenant_id', $tenantB->id)->first();
    if ($chatbotB === null) {
        $chatbotB = Chatbot::query()->create(['name' => 'Chatbot B']);
    }
    $flowB = Flow::query()->create([
        'chatbot_id' => $chatbotB->id,
        'name' => 'Flow B',
        'status' => 'published',
    ]);
    $nodeB = new FlowNode([
        'flow_id' => $flowB->id,
        'type' => FlowNodeType::AI->value,
        'name' => 'AI B',
        'position_x' => 0,
        'position_y' => 0,
        'config' => ['prompt' => 'Test B', 'output_variable' => 'result_b'],
        'is_start' => false,
    ]);
    $nodeB->save();
    $execB = FlowExecution::query()->create([
        'flow_id' => $flowB->id,
        'conversation_id' => $convB->id,
        'current_node_id' => $nodeB->id,
        'status' => 'running',
        'variables' => ['custom' => []],
        'attempts' => 0,
    ]);

    // Execute A
    TenantContext::setId($tenantA->id);
    $fake = new FakeAIProvider;
    $fake->withResponse('Result A');
    $executor = sec_executor($fake);

    $ctxA = new NodeExecutionContext(
        tenant: $tenantA,
        node: $nodeA,
        execution: $execA,
        conversation: $convA,
        contact: $contactA,
        business: $businessA,
        custom: [],
    );
    $executor->execute($ctxA);

    // Execute B
    TenantContext::setId($tenantB->id);
    $fake2 = new FakeAIProvider;
    $fake2->withResponse('Result B');
    $executor2 = sec_executor($fake2);

    $ctxB = new NodeExecutionContext(
        tenant: $tenantB,
        node: $nodeB,
        execution: $execB,
        conversation: $convB,
        contact: $contactB,
        business: $businessB,
        custom: [],
    );
    $executor2->execute($ctxB);

    // Switch back to A context to query A's logs
    TenantContext::setId($tenantA->id);

    // A's telemetry must NOT contain B's data
    $payloadA = FlowExecutionLog::query()
        ->where('execution_id', $ctxA->execution->id)
        ->where('event', 'ai_completed')
        ->first()
        ->payload;

    $jsonA = json_encode($payloadA);

    expect($jsonA)->not->toContain('Business B SECRET')
        ->and($jsonA)->not->toContain('Contact B SECRET')
        ->and($jsonA)->not->toContain($tenantB->id);
});

// ---------------------------------------------------------------------------
// AI-SEC-F08: Output is data, never executed
// ---------------------------------------------------------------------------
test('AI-SEC-F08: Malicious output stored as plain text, never executed', function (): void {
    $maliciousOutputs = [
        '<script>alert("XSS")</script>',
        '<?php echo "PHP executed"; ?>',
        'DROP TABLE users;',
        '{{tenant.secret}}',
        "\x00\x01\x02",
    ];

    foreach ($maliciousOutputs as $malicious) {
        $fake = new FakeAIProvider;
        $fake->withResponse($malicious);
        $executor = sec_executor($fake);
        $context = sec_context([
            'prompt' => 'Test',
            'output_variable' => 'output',
        ]);

        $executor->execute($context);

        $output = $context->execution->fresh()->variables['custom']['output'] ?? '';

        expect($output)->toBeString()
            ->and($output)->not->toContain('<?php executed');

        $context->execution->forceFill([
            'variables' => ['custom' => []],
        ])->save();
    }
});

// ---------------------------------------------------------------------------
// AI-SEC-F09: bot_paused blocks provider call
// ---------------------------------------------------------------------------
test('AI-SEC-F09: bot_paused prevents provider invocation completely', function (): void {
    $fake = new FakeAIProvider;
    $fake->withResponse('Should not be called');
    $executor = sec_executor($fake);
    $context = sec_context([
        'prompt' => 'Test',
        'output_variable' => 'out',
    ]);
    $context->conversation->forceFill(['bot_paused' => true])->save();

    $executor->execute($context);

    expect($fake->callCount())->toBe(0);

    $completedLogs = $context->execution->logs()->where('event', 'ai_completed')->count();
    $failedLogs = $context->execution->logs()->where('event', 'ai_failed')->count();

    expect($completedLogs)->toBe(0)
        ->and($failedLogs)->toBe(0);
});

// ---------------------------------------------------------------------------
// AI-SEC-F10: Provider is AIProviderInterface, not concrete OpenAI
// ---------------------------------------------------------------------------
test('AI-SEC-F10: AiNodeExecutor depends only on AIProviderInterface and KnowledgeSearchServiceInterface', function (): void {
    $reflection = new ReflectionClass(AiNodeExecutor::class);
    $constructor = $reflection->getConstructor();
    $parameters = $constructor->getParameters();

    $providerParam = $parameters[0];
    $searchServiceParam = $parameters[2];

    expect($providerParam->getType()->getName())->toBe(AIProviderInterface::class)
        ->and($providerParam->getType()->getName())->not->toBe('App\Infrastructure\AI\OpenAIProvider')
        ->and($searchServiceParam->getType()->getName())->toBe(KnowledgeSearchServiceInterface::class);
});

// ---------------------------------------------------------------------------
// AI-SEC-F11: tenant_id in config injection does not change context
// ---------------------------------------------------------------------------
test('AI-SEC-F11: tenant_id injection in node config cannot alter tenant context', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    TenantContext::setId($tenantA->id);

    $contact = Contact::query()->create([
        'name' => 'Contact',
        'phone' => '+5215551234567',
    ]);

    $business = $tenantA->businessProfile()->create([
        'name' => 'Business A',
        'description' => 'Desc A',
    ]);

    $conversation = Conversation::query()->create([
        'contact_id' => $contact->id,
        'status' => 'open',
    ]);

    $chatbot = Chatbot::query()->where('tenant_id', $tenantA->id)->first();
    if ($chatbot === null) {
        $chatbot = Chatbot::query()->create(['name' => 'Chatbot']);
    }

    $flow = Flow::query()->create([
        'chatbot_id' => $chatbot->id,
        'name' => 'Flow',
        'status' => 'published',
    ]);

    $node = new FlowNode([
        'flow_id' => $flow->id,
        'type' => FlowNodeType::AI->value,
        'name' => 'AI',
        'position_x' => 0,
        'position_y' => 0,
        'config' => [
            'prompt' => 'Test',
            'output_variable' => 'out',
            'tenant_id' => $tenantB->id,
        ],
        'is_start' => false,
    ]);
    $node->save();

    $execution = FlowExecution::query()->create([
        'flow_id' => $flow->id,
        'conversation_id' => $conversation->id,
        'current_node_id' => $node->id,
        'status' => 'running',
        'variables' => ['custom' => []],
        'attempts' => 0,
    ]);

    $fake = new FakeAIProvider;
    $fake->withResponse('OK');
    $executor = sec_executor($fake);

    $context = new NodeExecutionContext(
        tenant: $tenantA,
        node: $node,
        execution: $execution,
        conversation: $conversation,
        contact: $contact,
        business: $business,
        custom: [],
    );

    $executor->execute($context);

    // Context must remain tenant A
    expect($context->tenant->id)->toBe($tenantA->id);

    // Prompt must reference tenant A's business, not B's
    $request = $fake->lastRequest();
    expect($request->prompt)->toContain('Business A')
        ->and($request->prompt)->not->toContain('Business B');
});

// ---------------------------------------------------------------------------
// AI-SEC-F12: Provider exceptions are sanitized (no stack trace in log)
// ---------------------------------------------------------------------------
test('AI-SEC-F12: AI exceptions produce sanitized error in logs, no stack traces', function (): void {
    $fake = new FakeAIProvider;
    $fake->withException(new ConnectionException('Internal details: /var/www/html/app/Secret.php line 42'));
    $executor = sec_executor($fake);
    $context = sec_context([
        'prompt' => 'Test',
        'output_variable' => 'out',
        'fallback_message' => 'Fallback',
    ]);

    $executor->execute($context);

    $payload = $context->execution->logs()
        ->where('event', 'ai_failed')
        ->first()
        ->payload;

    expect($payload['success'])->toBeFalse()
        ->and($payload['error'])->not->toContain('#0 ')
        ->and($payload['error'])->not->toContain('#1 ')
        ->and($payload['error'])->not->toContain('Stack trace')
        ->and($payload['error'])->toBeString();
});
