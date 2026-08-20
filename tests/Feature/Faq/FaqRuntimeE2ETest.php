<?php

declare(strict_types=1);

use App\Application\Faq\Contracts\FaqMatcherServiceInterface;
use App\Application\Faq\Services\FaqReplyService;
use App\Application\Flows\Services\FlowEngine;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Faq\Enums\FaqStatus;
use App\Domain\Faq\Models\Faq;
use App\Domain\Faq\ValueObjects\FaqMatch;
use App\Domain\Flows\Enums\FlowStatus;
use App\Domain\Flows\Enums\FlowTriggerType;
use App\Domain\Flows\Models\Flow;
use App\Domain\Messages\Enums\MessageDirection;
use App\Domain\Messages\Models\Message;
use App\Domain\Tenants\Models\Tenant;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeFaqMatcherService;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
});

/*
|--------------------------------------------------------------------------
| FAQ Runtime E2E Tests (FASE 18 U4)
|--------------------------------------------------------------------------
|
| FAQ-E2E-01..03 — End-to-end: inbound → FlowEngine → FaqReplyService
| → outbound message. Usan FlowEngine real + FakeFaqMatcherService
| restringido vía binding override en cada test.
| Corren en SQLite :memory:.
*/

function e2e_publish_flow(Flow $flow, array $nodes, array $connections): Flow
{
    make_flow_graph($flow, $nodes, $connections);
    $flow->forceFill(['status' => FlowStatus::Published->value])->save();

    return $flow;
}

function e2e_conversation_for(Message $message): Conversation
{
    return Conversation::query()
        ->withoutTenantScope()
        ->whereKey($message->conversation_id)
        ->firstOrFail();
}

function e2e_outbound_count(Tenant $tenant): int
{
    return Message::query()
        ->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->where('direction', MessageDirection::Outbound->value)
        ->count();
}

it('FAQ-E2E-01: no flow match → FAQ matcher called → outbound created', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);
    $faq = Faq::factory()->create([
        'tenant_id' => $tenant->id,
        'question' => '¿Cuál es tu horario?',
        'answer' => 'Lunes a viernes de 9 a 18.',
        'normalized_question' => 'cual es tu horario',
        'status' => FaqStatus::Active,
    ]);
    TenantContext::clear();

    $fakeMatcher = new FakeFaqMatcherService;
    $fakeMatcher->whenMatch(new FaqMatch(
        faqId: $faq->id,
        answer: $faq->answer,
        matchType: 'exact_normalized',
        priority: 1,
    ));

    app()->instance(FaqMatcherServiceInterface::class, $fakeMatcher);

    $message = make_inbound_message($tenant, '¿Cuál es tu horario?');
    $conversation = e2e_conversation_for($message);

    TenantContext::setId($tenant->id);
    $result = app(FlowEngine::class)->handleMessage($tenant, $message, $conversation, function (Tenant $t, Message $m, Conversation $c): void {
        app(FaqReplyService::class)->tryReply($t, $m, $c);
    });
    TenantContext::clear();

    expect($result->handled)->toBeFalse();
    expect($fakeMatcher->matchCount())->toBe(1);
    expect(e2e_outbound_count($tenant))->toBe(1);
})->group('FAQ-E2E-01');

it('FAQ-E2E-02: flow matches → FAQ callback NOT called → only flow outbound', function (): void {
    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    e2e_publish_flow($flow, [
        ['id' => 'n1', 'type' => 'message', 'name' => 'Msg', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'end', 'name' => 'End'],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
    ]);

    make_trigger($flow, ['type' => FlowTriggerType::Start->value]);

    $fakeMatcher = new FakeFaqMatcherService;
    $fakeMatcher->whenMatch(new FaqMatch(
        faqId: 'would-match',
        answer: 'FAQ answer',
        matchType: 'exact_normalized',
        priority: 1,
    ));

    app()->instance(FaqMatcherServiceInterface::class, $fakeMatcher);

    $message = make_inbound_message($tenant, 'Hola');
    $conversation = e2e_conversation_for($message);

    TenantContext::setId($tenant->id);
    $result = app(FlowEngine::class)->handleMessage($tenant, $message, $conversation, function (Tenant $t, Message $m, Conversation $c): void {
        app(FaqReplyService::class)->tryReply($t, $m, $c);
    });
    TenantContext::clear();

    expect($result->handled)->toBeTrue();
    expect($fakeMatcher->matchCount())->toBe(0);
    expect(e2e_outbound_count($tenant))->toBe(1); // Only the flow message
})->group('FAQ-E2E-02');

it('FAQ-E2E-03: FAQ matcher returns null → no outbound created', function (): void {
    $tenant = Tenant::factory()->create();

    $fakeMatcher = new FakeFaqMatcherService;
    $fakeMatcher->whenMatch(null);

    app()->instance(FaqMatcherServiceInterface::class, $fakeMatcher);

    $message = make_inbound_message($tenant, 'Pregunta sin respuesta');
    $conversation = e2e_conversation_for($message);

    TenantContext::setId($tenant->id);
    $result = app(FlowEngine::class)->handleMessage($tenant, $message, $conversation, function (Tenant $t, Message $m, Conversation $c): void {
        app(FaqReplyService::class)->tryReply($t, $m, $c);
    });
    TenantContext::clear();

    expect($result->handled)->toBeFalse();
    expect($fakeMatcher->matchCount())->toBe(1);
    expect(e2e_outbound_count($tenant))->toBe(0);
})->group('FAQ-E2E-03');
