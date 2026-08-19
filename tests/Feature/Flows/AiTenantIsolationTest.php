<?php

declare(strict_types=1);

use App\Application\Flows\Services\AiPromptBuilder;
use App\Application\Flows\Services\Executors\AiNodeExecutor;
use App\Application\KnowledgeBase\Services\KnowledgeSearchService;
use App\Domain\AI\Contracts\EmbeddingProviderInterface;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Conversations\Models\Conversation;
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
use Tests\Fakes\FakeEmbeddingProvider;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
});

/*
|--------------------------------------------------------------------------
| FASE 16 U2 — AI MULTI-TENANCY TESTS
|--------------------------------------------------------------------------
|
| Tests AI-MT-01..06: aislamiento tenant del nodo AI.
|
*/

function mt_context_for(Tenant $tenant, array $custom = []): NodeExecutionContext
{
    TenantContext::setId($tenant->id);

    $contact = Contact::query()->create([
        'name' => 'Contact '.$tenant->id,
        'phone' => '+521555'.substr($tenant->id, 0, 7),
    ]);

    $business = $tenant->businessProfile()->create([
        'name' => 'Business '.$tenant->id,
        'description' => 'Desc for '.$tenant->id,
    ]);

    $conversation = Conversation::query()->create([
        'contact_id' => $contact->id,
        'status' => 'open',
    ]);

    $chatbot = Chatbot::query()
        ->where('tenant_id', $tenant->id)
        ->first() ?? create_mt_chatbot($tenant);

    $flow = Flow::query()->create([
        'chatbot_id' => $chatbot->id,
        'name' => 'MT Flow '.$tenant->id,
        'status' => 'published',
    ]);

    $node = new FlowNode([
        'flow_id' => $flow->id,
        'type' => 'ai',
        'name' => 'AI Node',
        'position_x' => 0,
        'position_y' => 0,
        'config' => [
            'prompt' => 'Responde al contacto {{contact.name}} del negocio {{business.name}}',
            'output_variable' => 'ai_output',
        ],
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

function create_mt_chatbot(Tenant $tenant): Chatbot
{
    $existing = Chatbot::query()
        ->where('tenant_id', $tenant->id)
        ->first();

    if ($existing) {
        return $existing;
    }

    TenantContext::setId($tenant->id);

    return Chatbot::query()->create(['name' => 'MT Chatbot']);
}

function make_mt_executor(?FakeAIProvider $fake = null): AiNodeExecutor
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

// ---------------------------------------------------------------------------
// AI-MT-01: Flow A usa business/contact/custom A
// ---------------------------------------------------------------------------
test('AI-MT-01: AI node uses correct tenant A business/contact/custom', function (): void {
    $tenantA = Tenant::factory()->create();
    $fake = new FakeAIProvider;
    $fake->withResponse('Response A');
    $executor = make_mt_executor($fake);

    $ctxA = mt_context_for($tenantA, ['my_var' => 'value_A']);
    $executor->execute($ctxA);

    $request = $fake->lastRequest();
    expect($request->prompt)->toContain('Business '.$tenantA->id)
        ->and($request->prompt)->toContain('Contact '.$tenantA->id)
        ->and($request->prompt)->toContain('value_A');
});

// ---------------------------------------------------------------------------
// AI-MT-02: Flow B usa únicamente B
// ---------------------------------------------------------------------------
test('AI-MT-02: AI node uses only tenant B data', function (): void {
    $tenantB = Tenant::factory()->create();
    $fake = new FakeAIProvider;
    $fake->withResponse('Response B');
    $executor = make_mt_executor($fake);

    $ctxB = mt_context_for($tenantB, ['other_var' => 'value_B']);
    $executor->execute($ctxB);

    $request = $fake->lastRequest();
    expect($request->prompt)->toContain('Business '.$tenantB->id)
        ->and($request->prompt)->toContain('value_B');
});

// ---------------------------------------------------------------------------
// AI-MT-03: AI output A se guarda solo en execution A
// ---------------------------------------------------------------------------
test('AI-MT-03: AI output saved only in tenant A execution', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $fake = new FakeAIProvider;
    $fake->withResponse('Tenant A output');
    $executor = make_mt_executor($fake);

    $ctxA = mt_context_for($tenantA);
    $executor->execute($ctxA);

    $ctxB = mt_context_for($tenantB);

    expect($ctxB->execution->variables['custom'])->not->toHaveKey('ai_output')
        ->and($ctxA->execution->fresh()->variables['custom']['ai_output'])->toBe('Tenant A output');
});

// ---------------------------------------------------------------------------
// AI-MT-04: Template de A no puede resolver custom B
// ---------------------------------------------------------------------------
test('AI-MT-04: Template from A cannot resolve custom B variables', function (): void {
    $tenantA = Tenant::factory()->create();
    $fake = new FakeAIProvider;
    $fake->withResponse('OK');
    $executor = make_mt_executor($fake);

    $ctxA = mt_context_for($tenantA, ['secret_a' => 'only_for_a']);
    $executor->execute($ctxA);

    $tenantB = Tenant::factory()->create();
    $ctxB = mt_context_for($tenantB);

    expect($ctxB->custom)->not->toHaveKey('secret_a');
});

// ---------------------------------------------------------------------------
// AI-MT-05: TenantContext incorrecto falla cerrado/no mezcla
// ---------------------------------------------------------------------------
test('AI-MT-05: Wrong tenant context does not leak data', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $fake = new FakeAIProvider;
    $fake->withResponse('Mixed data');
    $executor = make_mt_executor($fake);

    $ctxA = mt_context_for($tenantA);
    TenantContext::setId($tenantB->id);

    $executor->execute($ctxA);

    TenantContext::clear();

    $request = $fake->lastRequest();
    expect($request->prompt)->toContain('Business '.$tenantA->id)
        ->and($request->prompt)->not->toContain('Business '.$tenantB->id);
});

// ---------------------------------------------------------------------------
// AI-MT-06: job/worker secuencial A→B limpia contexto
// ---------------------------------------------------------------------------
test('AI-MT-06: Sequential execution A then B cleans tenant context', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $fake = new FakeAIProvider;
    $fake->withResponse('Response');
    $executor = make_mt_executor($fake);

    $ctxA = mt_context_for($tenantA);
    $executor->execute($ctxA);

    TenantContext::clear();

    $ctxB = mt_context_for($tenantB);
    $executor->execute($ctxB);

    $requests = $fake->capturedRequests();
    expect($requests[0]->prompt)->toContain('Business '.$tenantA->id)
        ->and($requests[1]->prompt)->toContain('Business '.$tenantB->id)
        ->and($requests[0]->prompt)->not->toContain('Business '.$tenantB->id)
        ->and($requests[1]->prompt)->not->toContain('Business '.$tenantA->id);
});
