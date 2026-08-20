<?php

declare(strict_types=1);

use App\Application\Flows\Services\FlowEngine;
use App\Application\Flows\ValueObjects\FlowHandleResult;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Flows\Enums\FlowStatus;
use App\Domain\Flows\Enums\FlowTriggerType;
use App\Domain\Flows\Models\Flow;
use App\Domain\Messages\Models\Message;
use App\Domain\Tenants\Models\Tenant;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
});

function faq_publish_flow(Flow $flow, array $nodes, array $connections): Flow
{
    make_flow_graph($flow, $nodes, $connections);
    $flow->forceFill(['status' => FlowStatus::Published->value])->save();

    return $flow;
}

function faq_engine_conversation_for(Message $message): Conversation
{
    return Conversation::query()
        ->withoutTenantScope()
        ->whereKey($message->conversation_id)
        ->firstOrFail();
}

/*
|--------------------------------------------------------------------------
| FAQ Runtime Precedence Tests (FASE 18 U4)
|--------------------------------------------------------------------------
|
| FAQ-PREC-01..10 — Verifican la precedencia del motor dentro de FlowEngine
| cuando se invoca el callback FAQ onUnhandled. Bajo lock de conversación.
| Corren en SQLite :memory:.
*/

it('FAQ-PREC-01: when no flow trigger matches, onUnhandled callback is called', function (): void {
    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    // Publish a flow but with a keyword trigger that DOES NOT match
    faq_publish_flow($flow, [
        ['id' => 'n1', 'type' => 'message', 'name' => 'Msg', 'config' => ['text' => 'Hi'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'end', 'name' => 'End'],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
    ]);

    make_trigger($flow, ['type' => FlowTriggerType::Keyword->value, 'keyword' => 'ofertas']);

    $message = make_inbound_message($tenant, 'Hola mundo');
    $conversation = faq_engine_conversation_for($message);

    $callbackCalled = false;
    $engine = app(FlowEngine::class);

    TenantContext::setId($tenant->id);
    $result = $engine->handleMessage($tenant, $message, $conversation, function () use (&$callbackCalled): void {
        $callbackCalled = true;
    });
    TenantContext::clear();

    expect($callbackCalled)->toBeTrue();
    expect($result->handled)->toBeFalse();
})->group('FAQ-PREC-01');

it('FAQ-PREC-02: when a flow trigger matches, onUnhandled callback is NOT called', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    faq_publish_flow($flow, [
        ['id' => 'n1', 'type' => 'message', 'name' => 'Msg', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'end', 'name' => 'End'],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
    ]);

    make_trigger($flow, ['type' => FlowTriggerType::Keyword->value, 'keyword' => 'hola']);

    $message = make_inbound_message($tenant, 'hola');
    $conversation = faq_engine_conversation_for($message);

    $callbackCalled = false;
    $engine = app(FlowEngine::class);

    TenantContext::setId($tenant->id);
    $result = $engine->handleMessage($tenant, $message, $conversation, function () use (&$callbackCalled): void {
        $callbackCalled = true;
    });
    TenantContext::clear();

    expect($callbackCalled)->toBeFalse();
    expect($result->handled)->toBeTrue();
})->group('FAQ-PREC-02');

it('FAQ-PREC-03: bot_paused returns handled=false and callback is NOT called', function (): void {
    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    $message = make_inbound_message($tenant, 'Test');
    $conversation = faq_engine_conversation_for($message);
    $conversation->forceFill(['bot_paused' => true])->save();

    $callbackCalled = false;
    $engine = app(FlowEngine::class);

    TenantContext::setId($tenant->id);
    $result = $engine->handleMessage($tenant, $message, $conversation, function () use (&$callbackCalled): void {
        $callbackCalled = true;
    });
    TenantContext::clear();

    expect($callbackCalled)->toBeFalse();
    expect($result->handled)->toBeFalse();
})->group('FAQ-PREC-03');

it('FAQ-PREC-04: active execution at question node returns handled=true and callback is NOT called', function (): void {
    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    faq_publish_flow($flow, [
        ['id' => 'n1', 'type' => 'message', 'name' => 'Msg', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'question', 'name' => 'Ask', 'config' => ['question' => '¿Qué necesitas?']],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
    ]);

    make_trigger($flow, ['type' => FlowTriggerType::Start->value]);

    $message1 = make_inbound_message($tenant, 'Hola');
    $conversation = faq_engine_conversation_for($message1);

    TenantContext::setId($tenant->id);
    app(FlowEngine::class)->handleMessage($tenant, $message1, $conversation);
    TenantContext::clear();

    $message2 = make_inbound_message($tenant, 'Quiero ayuda');
    $conversation->refresh();

    $callbackCalled = false;
    $engine = app(FlowEngine::class);

    TenantContext::setId($tenant->id);
    $result = $engine->handleMessage($tenant, $message2, $conversation, function () use (&$callbackCalled): void {
        $callbackCalled = true;
    });
    TenantContext::clear();

    expect($callbackCalled)->toBeFalse();
    expect($result->handled)->toBeTrue();
})->group('FAQ-PREC-04');

it('FAQ-PREC-05: start trigger matching returns handled=true and callback is NOT called', function (): void {
    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    faq_publish_flow($flow, [
        ['id' => 'n1', 'type' => 'message', 'name' => 'Msg', 'config' => ['text' => 'Welcome'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'end', 'name' => 'End'],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
    ]);

    make_trigger($flow, ['type' => FlowTriggerType::Start->value]);

    $message = make_inbound_message($tenant, 'Any message');
    $conversation = faq_engine_conversation_for($message);

    $callbackCalled = false;
    $engine = app(FlowEngine::class);

    TenantContext::setId($tenant->id);
    $result = $engine->handleMessage($tenant, $message, $conversation, function () use (&$callbackCalled): void {
        $callbackCalled = true;
    });
    TenantContext::clear();

    expect($callbackCalled)->toBeFalse();
    expect($result->handled)->toBeTrue();
})->group('FAQ-PREC-05');

it('FAQ-PREC-06: onUnhandled is null (default) does not crash when no flow matches', function (): void {
    $tenant = Tenant::factory()->create();
    $message = make_inbound_message($tenant, 'Test');
    $conversation = faq_engine_conversation_for($message);

    $engine = app(FlowEngine::class);

    TenantContext::setId($tenant->id);
    $result = $engine->handleMessage($tenant, $message, $conversation);
    TenantContext::clear();

    expect($result->handled)->toBeFalse();
})->group('FAQ-PREC-06');

it('FAQ-PREC-07: FlowHandleResult with handled=false', function (): void {
    $result = new FlowHandleResult(handled: false);

    expect($result->handled)->toBeFalse();
})->group('FAQ-PREC-07');

it('FAQ-PREC-08: FlowHandleResult with handled=true', function (): void {
    $result = new FlowHandleResult(handled: true);

    expect($result->handled)->toBeTrue();
})->group('FAQ-PREC-08');

it('FAQ-PREC-09: onUnhandled callback is NOT called when null callback is provided', function (): void {
    $tenant = Tenant::factory()->create();
    $message = make_inbound_message($tenant, 'No callback');
    $conversation = faq_engine_conversation_for($message);

    $engine = app(FlowEngine::class);

    TenantContext::setId($tenant->id);
    $result = $engine->handleMessage($tenant, $message, $conversation);
    TenantContext::clear();

    expect($result->handled)->toBeFalse();
})->group('FAQ-PREC-09');

it('FAQ-PREC-10: FAQ callback receives correct tenant, message, conversation objects', function (): void {
    $tenant = Tenant::factory()->create();
    $message = make_inbound_message($tenant, 'Callback args');
    $conversation = faq_engine_conversation_for($message);

    $received = ['tenant' => null, 'message' => null, 'conversation' => null];
    $engine = app(FlowEngine::class);

    TenantContext::setId($tenant->id);
    $engine->handleMessage($tenant, $message, $conversation, function (Tenant $t, Message $m, Conversation $c) use (&$received): void {
        $received['tenant'] = $t;
        $received['message'] = $m;
        $received['conversation'] = $c;
    });
    TenantContext::clear();

    expect($received['tenant'])->toBeInstanceOf(Tenant::class);
    expect($received['tenant']->id)->toBe($tenant->id);
    expect($received['message'])->toBeInstanceOf(Message::class);
    expect($received['message']->id)->toBe($message->id);
    expect($received['conversation'])->toBeInstanceOf(Conversation::class);
    expect($received['conversation']->id)->toBe($conversation->id);
})->group('FAQ-PREC-10');
