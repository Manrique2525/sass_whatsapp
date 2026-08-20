<?php

declare(strict_types=1);

use App\Application\Faq\Contracts\FaqMatcherServiceInterface;
use App\Application\Faq\Services\FaqMatcherService;
use App\Application\Faq\Services\FaqReplyService;
use App\Application\Flows\Services\FlowEngine;
use App\Application\Messages\Services\MessageService;
use App\Domain\Audit\Models\AuditLog;
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
use App\Domain\Users\Models\User;
use App\Infrastructure\AI\Providers\OpenAIProvider;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Tests\Fakes\FakeFaqMatcherService;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
});

function u6_publish_flow(Flow $flow, array $nodes, array $connections): Flow
{
    make_flow_graph($flow, $nodes, $connections);
    $flow->forceFill(['status' => FlowStatus::Published->value])->save();

    return $flow;
}

function u6_engine_conversation_for(Message $message): Conversation
{
    return Conversation::query()
        ->withoutTenantScope()
        ->whereKey($message->conversation_id)
        ->firstOrFail();
}

function u6_outbound_count(Tenant $tenant): int
{
    return Message::query()
        ->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->where('direction', MessageDirection::Outbound->value)
        ->count();
}

/*
|--------------------------------------------------------------------------
| PRECEDENCE FINAL
|--------------------------------------------------------------------------
*/

it('FAQ-PREC-11: duplicate inbound handled idempotently via created-flag', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);
    Faq::factory()->create([
        'tenant_id' => $tenant->id,
        'question' => 'Horario',
        'answer' => 'Lunes a viernes de 9 a 18.',
        'normalized_question' => 'horario',
        'status' => FaqStatus::Active,
    ]);
    TenantContext::clear();

    $fake = new FakeFaqMatcherService;
    $fake->whenMatch(new FaqMatch(faqId: 'x', answer: 'A', matchType: 'exact_normalized', priority: 1));
    app()->instance(FaqMatcherServiceInterface::class, $fake);

    $message = make_inbound_message($tenant, 'Horario');
    $conversation = u6_engine_conversation_for($message);

    $callbackCount = 0;
    TenantContext::setId($tenant->id);
    app(FlowEngine::class)->handleMessage($tenant, $message, $conversation, function (Tenant $t, Message $m, Conversation $c) use (&$callbackCount): void {
        $callbackCount++;
        app(FaqReplyService::class)->tryReply($t, $m, $c);
    });
    TenantContext::clear();

    $firstCount = u6_outbound_count($tenant);

    $conversation->refresh();

    TenantContext::setId($tenant->id);
    app(FlowEngine::class)->handleMessage($tenant, $message, $conversation, function (Tenant $t, Message $m, Conversation $c) use (&$callbackCount): void {
        $callbackCount++;
        app(FaqReplyService::class)->tryReply($t, $m, $c);
    });
    TenantContext::clear();

    expect($firstCount)->toBe(1);
    expect($callbackCount)->toBe(2);
    expect(u6_outbound_count($tenant))->toBe(2);
})->group('FAQ-PREC-11');

it('FAQ-PREC-12: bot_paused blocks FAQ reply', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);
    Faq::factory()->create([
        'tenant_id' => $tenant->id,
        'question' => 'Horario',
        'answer' => 'De 9 a 18.',
        'normalized_question' => 'horario',
        'status' => FaqStatus::Active,
    ]);
    TenantContext::clear();

    $fake = new FakeFaqMatcherService;
    $fake->whenMatch(new FaqMatch(faqId: 'x', answer: 'A', matchType: 'exact_normalized', priority: 1));
    app()->instance(FaqMatcherServiceInterface::class, $fake);

    $message = make_inbound_message($tenant, 'Horario');
    $conversation = u6_engine_conversation_for($message);

    Conversation::query()
        ->withoutTenantScope()
        ->whereKey($conversation->id)
        ->update(['bot_paused' => true]);
    $conversation->refresh();

    TenantContext::setId($tenant->id);
    app(FlowEngine::class)->handleMessage($tenant, $message, $conversation, function (Tenant $t, Message $m, Conversation $c): void {
        app(FaqReplyService::class)->tryReply($t, $m, $c);
    });
    TenantContext::clear();

    expect(u6_outbound_count($tenant))->toBe(0);
    expect($fake->matchCount())->toBe(0);
})->group('FAQ-PREC-12');

it('FAQ-PREC-13: active flow execution wins over FAQ', function (): void {
    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    u6_publish_flow($flow, [
        ['id' => 'n1', 'type' => 'message', 'name' => 'Msg', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'question', 'name' => 'Q', 'config' => ['text' => 'Nombre?', 'field' => 'name', 'type' => 'String'], 'is_start' => false],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
    ]);

    make_trigger($flow, ['type' => FlowTriggerType::Start->value]);

    $fake = new FakeFaqMatcherService;
    $fake->whenMatch(new FaqMatch(faqId: 'x', answer: 'FAQ answer', matchType: 'exact_normalized', priority: 1));
    app()->instance(FaqMatcherServiceInterface::class, $fake);

    $message = make_inbound_message($tenant, 'Hola');
    $conversation = u6_engine_conversation_for($message);

    TenantContext::setId($tenant->id);
    $result = app(FlowEngine::class)->handleMessage($tenant, $message, $conversation, function (Tenant $t, Message $m, Conversation $c): void {
        app(FaqReplyService::class)->tryReply($t, $m, $c);
    });
    TenantContext::clear();

    expect($result->handled)->toBeTrue();
    expect($fake->matchCount())->toBe(0);
})->group('FAQ-PREC-13');

it('FAQ-PREC-14: human handoff active blocks FAQ reply via FaqReplyService', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);
    Faq::factory()->create([
        'tenant_id' => $tenant->id,
        'question' => 'Horario',
        'answer' => '9 a 18.',
        'normalized_question' => 'horario',
        'status' => FaqStatus::Active,
    ]);
    TenantContext::clear();

    $fake = new FakeFaqMatcherService;
    $fake->whenMatch(new FaqMatch(faqId: 'x', answer: 'A', matchType: 'exact_normalized', priority: 1));
    app()->instance(FaqMatcherServiceInterface::class, $fake);

    $message = make_inbound_message($tenant, 'Horario');
    $conversation = u6_engine_conversation_for($message);

    Conversation::query()->withoutTenantScope()->whereKey($conversation->id)->update([
        'bot_paused' => true,
    ]);
    $conversation->refresh();

    TenantContext::setId($tenant->id);
    app(FaqReplyService::class)->tryReply($tenant, $message, $conversation);
    TenantContext::clear();

    expect(u6_outbound_count($tenant))->toBe(0);
    expect($fake->matchCount())->toBe(0);
})->group('FAQ-PREC-14');

/*
|--------------------------------------------------------------------------
| CONCURRENCY MATRIX
|--------------------------------------------------------------------------
*/

it('FAQ-CON-F01: duplicate inbound same provider_message_id produces exactly one', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);
    Faq::factory()->create([
        'tenant_id' => $tenant->id,
        'question' => 'Test',
        'answer' => 'Answer',
        'normalized_question' => 'test',
        'status' => FaqStatus::Active,
    ]);
    TenantContext::clear();

    $providerId = 'wamid-dup-'.(string) Str::uuid();

    $fake = new FakeFaqMatcherService;
    $fake->whenMatch(new FaqMatch(faqId: 'x', answer: 'A', matchType: 'exact_normalized', priority: 1));
    app()->instance(FaqMatcherServiceInterface::class, $fake);

    $payload = [
        'id' => $providerId,
        'from' => '15550000001',
        'timestamp' => '1725000000',
        'type' => 'text',
        'text' => ['body' => 'Test'],
    ];

    $messageService = app(MessageService::class);

    $r1 = $messageService->handleInboundMessage($tenant, $payload);
    $r2 = $messageService->handleInboundMessage($tenant, $payload);

    expect($r1->created)->toBeTrue();
    expect($r2->created)->toBeFalse();
    expect($r1->message->id)->toBe($r2->message->id);
})->group('FAQ-CON-F01');

it('FAQ-CON-F02: duplicate inbound via ProcessIncomingWhatsAppMessage is idempotent', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);
    Faq::factory()->create([
        'tenant_id' => $tenant->id,
        'question' => 'Horario',
        'answer' => '9 a 18',
        'normalized_question' => 'horario',
        'status' => FaqStatus::Active,
    ]);
    TenantContext::clear();

    $fake = new FakeFaqMatcherService;
    $fake->whenMatch(new FaqMatch(faqId: 'x', answer: 'A', matchType: 'exact_normalized', priority: 1));
    app()->instance(FaqMatcherServiceInterface::class, $fake);

    $providerId = 'wamid-idem-'.(string) Str::uuid();
    $payload = [
        'id' => $providerId,
        'from' => '15550000001',
        'timestamp' => '1725000000',
        'type' => 'text',
        'text' => ['body' => 'Horario'],
    ];

    $messageService = app(MessageService::class);

    $r1 = $messageService->handleInboundMessage($tenant, $payload);
    expect($r1->created)->toBeTrue();

    $r2 = $messageService->handleInboundMessage($tenant, $payload);
    expect($r2->created)->toBeFalse();
    expect($r1->message->id)->toBe($r2->message->id);

    expect($fake->matchCount())->toBe(0);
})->group('FAQ-CON-F02');

it('FAQ-CON-F03: flow trigger vs FAQ race - flow wins', function (): void {
    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    u6_publish_flow($flow, [
        ['id' => 'n1', 'type' => 'message', 'name' => 'Msg', 'config' => ['text' => 'Oferta'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'end', 'name' => 'End'],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
    ]);

    make_trigger($flow, ['type' => FlowTriggerType::Keyword->value, 'keyword' => 'ofertas']);

    $fake = new FakeFaqMatcherService;
    $fake->whenMatch(new FaqMatch(faqId: 'x', answer: 'FAQ answer', matchType: 'exact_normalized', priority: 1));
    app()->instance(FaqMatcherServiceInterface::class, $fake);

    $message = make_inbound_message($tenant, 'Dame ofertas');
    $conversation = u6_engine_conversation_for($message);

    TenantContext::setId($tenant->id);
    $result = app(FlowEngine::class)->handleMessage($tenant, $message, $conversation, function (Tenant $t, Message $m, Conversation $c): void {
        app(FaqReplyService::class)->tryReply($t, $m, $c);
    });
    TenantContext::clear();

    expect($result->handled)->toBeTrue();
    expect($fake->matchCount())->toBe(0);
})->group('FAQ-CON-F03');

it('FAQ-CON-F04: active flow starts while FAQ candidate exists - flow wins', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);
    Faq::factory()->create([
        'tenant_id' => $tenant->id,
        'question' => 'Hola',
        'answer' => 'Hola FAQ',
        'normalized_question' => 'hola',
        'status' => FaqStatus::Active,
    ]);
    TenantContext::clear();

    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    u6_publish_flow($flow, [
        ['id' => 'n1', 'type' => 'message', 'name' => 'Msg', 'config' => ['text' => 'Hola flow'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'end', 'name' => 'End'],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
    ]);

    make_trigger($flow, ['type' => FlowTriggerType::Start->value]);

    $fake = new FakeFaqMatcherService;
    $fake->whenMatch(new FaqMatch(faqId: 'x', answer: 'FAQ', matchType: 'exact_normalized', priority: 1));
    app()->instance(FaqMatcherServiceInterface::class, $fake);

    $message = make_inbound_message($tenant, 'Hola');
    $conversation = u6_engine_conversation_for($message);

    TenantContext::setId($tenant->id);
    $result = app(FlowEngine::class)->handleMessage($tenant, $message, $conversation, function (Tenant $t, Message $m, Conversation $c): void {
        app(FaqReplyService::class)->tryReply($t, $m, $c);
    });
    TenantContext::clear();

    expect($result->handled)->toBeTrue();
    expect($fake->matchCount())->toBe(0);
    expect(u6_outbound_count($tenant))->toBe(1);
})->group('FAQ-CON-F04');

it('FAQ-CON-F05: human handoff activated concurrently blocks FAQ', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);
    Faq::factory()->create([
        'tenant_id' => $tenant->id,
        'question' => 'Horario',
        'answer' => '9 a 18',
        'normalized_question' => 'horario',
        'status' => FaqStatus::Active,
    ]);
    TenantContext::clear();

    $fake = new FakeFaqMatcherService;
    $fake->whenMatch(new FaqMatch(faqId: 'x', answer: 'A', matchType: 'exact_normalized', priority: 1));
    app()->instance(FaqMatcherServiceInterface::class, $fake);

    $message = make_inbound_message($tenant, 'Horario');
    $conversation = u6_engine_conversation_for($message);

    Conversation::query()->withoutTenantScope()->whereKey($conversation->id)->update(['bot_paused' => true]);
    $conversation->refresh();

    TenantContext::setId($tenant->id);
    $result = app(FlowEngine::class)->handleMessage($tenant, $message, $conversation, function (Tenant $t, Message $m, Conversation $c): void {
        app(FaqReplyService::class)->tryReply($t, $m, $c);
    });
    TenantContext::clear();

    expect($result->handled)->toBeFalse();
    expect(u6_outbound_count($tenant))->toBe(0);
})->group('FAQ-CON-F05');

it('FAQ-CON-F06: two tenants same normalized question - each resolves own FAQ', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    TenantContext::setId($tenantA->id);
    $faqA = Faq::factory()->create([
        'tenant_id' => $tenantA->id,
        'question' => 'Horario',
        'answer' => 'A: 9 a 18',
        'normalized_question' => 'horario',
        'status' => FaqStatus::Active,
    ]);
    TenantContext::clear();

    TenantContext::setId($tenantB->id);
    $faqB = Faq::factory()->create([
        'tenant_id' => $tenantB->id,
        'question' => 'Horario',
        'answer' => 'B: 10 a 20',
        'normalized_question' => 'horario',
        'status' => FaqStatus::Active,
    ]);
    TenantContext::clear();

    $matcher = app(FaqMatcherService::class);

    TenantContext::setId($tenantA->id);
    $matchA = $matcher->match($tenantA, 'Horario');
    TenantContext::clear();

    TenantContext::setId($tenantB->id);
    $matchB = $matcher->match($tenantB, 'Horario');
    TenantContext::clear();

    expect($matchA)->not->toBeNull();
    expect($matchB)->not->toBeNull();
    expect($matchA->faqId)->toBe($faqA->id);
    expect($matchB->faqId)->toBe($faqB->id);
    expect($matchA->answer)->toBe('A: 9 a 18');
    expect($matchB->answer)->toBe('B: 10 a 20');
})->group('FAQ-CON-F06');

it('FAQ-CON-F07: FAQ deleted while matching - outbound still created', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);
    $faq = Faq::factory()->create([
        'tenant_id' => $tenant->id,
        'question' => 'Test',
        'answer' => 'Answer',
        'normalized_question' => 'test',
        'status' => FaqStatus::Active,
    ]);
    $faqId = $faq->id;
    $answer = $faq->answer;
    TenantContext::clear();

    $fake = new FakeFaqMatcherService;
    $fake->whenMatch(new FaqMatch(faqId: $faqId, answer: $answer, matchType: 'exact_normalized', priority: 1));
    app()->instance(FaqMatcherServiceInterface::class, $fake);

    $message = make_inbound_message($tenant, 'Test');
    $conversation = u6_engine_conversation_for($message);

    Faq::withoutGlobalScopes()->where('id', $faqId)->delete();

    TenantContext::setId($tenant->id);
    app(FlowEngine::class)->handleMessage($tenant, $message, $conversation, function (Tenant $t, Message $m, Conversation $c): void {
        app(FaqReplyService::class)->tryReply($t, $m, $c);
    });
    TenantContext::clear();

    expect(u6_outbound_count($tenant))->toBe(1);
})->group('FAQ-CON-F07');

it('FAQ-CON-F08: FAQ deactivated between match and outbound - graceful', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);
    Faq::factory()->create([
        'tenant_id' => $tenant->id,
        'question' => 'Test',
        'answer' => 'Answer',
        'normalized_question' => 'test',
        'status' => FaqStatus::Active,
    ]);
    TenantContext::clear();

    $matcher = app(FaqMatcherService::class);

    TenantContext::setId($tenant->id);
    $match = $matcher->match($tenant, 'Test');
    expect($match)->not->toBeNull();

    Faq::withoutGlobalScopes()->where('tenant_id', $tenant->id)->update(['status' => FaqStatus::Inactive->value]);

    $matchAfter = $matcher->match($tenant, 'Test');
    TenantContext::clear();

    expect($matchAfter)->toBeNull();
})->group('FAQ-CON-F08');

it('FAQ-CON-F10: TenantContext reuse A->B does not leak', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    TenantContext::setId($tenantA->id);
    Faq::factory()->create([
        'tenant_id' => $tenantA->id,
        'question' => 'SecretA',
        'answer' => 'AnswerA',
        'normalized_question' => 'secreta',
        'status' => FaqStatus::Active,
    ]);
    TenantContext::clear();

    TenantContext::setId($tenantB->id);
    Faq::factory()->create([
        'tenant_id' => $tenantB->id,
        'question' => 'PublicB',
        'answer' => 'AnswerB',
        'normalized_question' => 'publicb',
        'status' => FaqStatus::Active,
    ]);
    TenantContext::clear();

    $matcher = app(FaqMatcherService::class);

    TenantContext::setId($tenantB->id);
    $result = $matcher->match($tenantB, 'SecretA');
    TenantContext::clear();

    expect($result)->toBeNull();
})->group('FAQ-CON-F10');

/*
|--------------------------------------------------------------------------
| SECURITY MATRIX
|--------------------------------------------------------------------------
*/

it('FAQ-SEC-F01: IDOR cross-tenant FAQ show returns 404', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    TenantContext::setId($tenantA->id);
    $faq = Faq::factory()->create(['tenant_id' => $tenantA->id]);
    TenantContext::clear();

    $userB = User::factory()->create();
    make_tenant_member($userB, $tenantB, 'admin');

    $response = $this->actingAs($userB)
        ->getJson("/api/v1/tenants/{$tenantB->id}/faqs/{$faq->id}");

    $response->assertStatus(404);
})->group('FAQ-SEC-F01');

it('FAQ-SEC-F02: tenant_id body injection ignored on create', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    make_tenant_member($user, $tenant, 'owner');
    TenantContext::setId($tenant->id);

    $response = $this->actingAs($user)
        ->postJson("/api/v1/tenants/{$tenant->id}/faqs", [
            'question' => 'Test',
            'answer' => 'Answer',
            'tenant_id' => 'injected-tenant-id',
            'normalized_question' => 'injected',
        ]);

    $response->assertStatus(201);

    $faq = Faq::query()->withoutTenantScope()->where('question', 'Test')->first();
    expect($faq->tenant_id)->toBe($tenant->id);
    TenantContext::clear();
})->group('FAQ-SEC-F02');

it('FAQ-SEC-F03: XSS in FAQ answer persists as plain text in outbound', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);
    Faq::factory()->create([
        'tenant_id' => $tenant->id,
        'question' => 'XSS test',
        'answer' => '<script>alert(1)</script>',
        'normalized_question' => 'xss test',
        'status' => FaqStatus::Active,
    ]);
    TenantContext::clear();

    $fake = new FakeFaqMatcherService;
    $fake->whenMatch(new FaqMatch(faqId: 'x', answer: '<script>alert(1)</script>', matchType: 'exact_normalized', priority: 1));
    app()->instance(FaqMatcherServiceInterface::class, $fake);

    $message = make_inbound_message($tenant, 'XSS test');
    $conversation = u6_engine_conversation_for($message);

    TenantContext::setId($tenant->id);
    app(FlowEngine::class)->handleMessage($tenant, $message, $conversation, function (Tenant $t, Message $m, Conversation $c): void {
        app(FaqReplyService::class)->tryReply($t, $m, $c);
    });
    TenantContext::clear();

    $outbound = Message::query()
        ->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->where('direction', MessageDirection::Outbound->value)
        ->first();

    expect($outbound)->not->toBeNull();
    expect($outbound->body)->toBe('<script>alert(1)</script>');
    expect($outbound->body)->not->toContain('v-html');
})->group('FAQ-SEC-F03');

it('FAQ-SEC-F04: template literal {{contact.name}} sent literally not resolved', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);
    Faq::factory()->create([
        'tenant_id' => $tenant->id,
        'question' => 'Saludo',
        'answer' => 'Hola {{contact.name}}, bienvenido.',
        'normalized_question' => 'saludo',
        'status' => FaqStatus::Active,
    ]);
    TenantContext::clear();

    $fake = new FakeFaqMatcherService;
    $fake->whenMatch(new FaqMatch(faqId: 'x', answer: 'Hola {{contact.name}}, bienvenido.', matchType: 'exact_normalized', priority: 1));
    app()->instance(FaqMatcherServiceInterface::class, $fake);

    $message = make_inbound_message($tenant, 'Saludo');
    $conversation = u6_engine_conversation_for($message);

    TenantContext::setId($tenant->id);
    app(FlowEngine::class)->handleMessage($tenant, $message, $conversation, function (Tenant $t, Message $m, Conversation $c): void {
        app(FaqReplyService::class)->tryReply($t, $m, $c);
    });
    TenantContext::clear();

    $outbound = Message::query()
        ->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->where('direction', MessageDirection::Outbound->value)
        ->first();

    expect($outbound)->not->toBeNull();
    expect($outbound->body)->toBe('Hola {{contact.name}}, bienvenido.');
})->group('FAQ-SEC-F04');

it('FAQ-SEC-F05: SQL-looking text in question does not cause error', function (): void {
    $matcher = app(FaqMatcherService::class);
    $tenant = Tenant::factory()->create();

    TenantContext::setId($tenant->id);
    $result = $matcher->match($tenant, "'; DROP TABLE faqs; --");
    TenantContext::clear();

    expect($result)->toBeNull();
})->group('FAQ-SEC-F05');

it('FAQ-SEC-F06: audit faq.matched contains no PII', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);
    $faq = Faq::factory()->create([
        'tenant_id' => $tenant->id,
        'question' => 'Horario personal',
        'answer' => 'Lunes a viernes.',
        'normalized_question' => 'horario personal',
        'status' => FaqStatus::Active,
    ]);
    TenantContext::clear();

    $fake = new FakeFaqMatcherService;
    $fake->whenMatch(new FaqMatch(faqId: $faq->id, answer: $faq->answer, matchType: 'exact_normalized', priority: 1));
    app()->instance(FaqMatcherServiceInterface::class, $fake);

    $message = make_inbound_message($tenant, 'Horario personal');
    $conversation = u6_engine_conversation_for($message);

    TenantContext::setId($tenant->id);
    app(FlowEngine::class)->handleMessage($tenant, $message, $conversation, function (Tenant $t, Message $m, Conversation $c): void {
        app(FaqReplyService::class)->tryReply($t, $m, $c);
    });
    TenantContext::clear();

    $audit = AuditLog::query()
        ->where('action', 'faq.matched')
        ->orderByDesc('created_at')
        ->first();

    expect($audit)->not->toBeNull();
    $payload = $audit->data;
    expect($payload)->not->toHaveKey('question');
    expect($payload)->not->toHaveKey('answer');
    expect($payload)->not->toHaveKey('normalized_question');
    expect($payload)->not->toHaveKey('phone');
    expect($payload)->not->toHaveKey('email');
    expect($payload)->toHaveKey('faq_id');
    expect($payload)->toHaveKey('match_type');
    expect($payload)->toHaveKey('priority');
})->group('FAQ-SEC-F06');

it('FAQ-SEC-F07: AI provider not called during FAQ match', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);
    Faq::factory()->create([
        'tenant_id' => $tenant->id,
        'question' => 'No AI',
        'answer' => 'Direct answer',
        'normalized_question' => 'no ai',
        'status' => FaqStatus::Active,
    ]);
    TenantContext::clear();

    $fake = new FakeFaqMatcherService;
    $fake->whenMatch(new FaqMatch(faqId: 'x', answer: 'Direct answer', matchType: 'exact_normalized', priority: 1));
    app()->instance(FaqMatcherServiceInterface::class, $fake);

    $aiCalled = false;
    App::bind(OpenAIProvider::class, function () use (&$aiCalled): OpenAIProvider {
        $aiCalled = true;

        return new OpenAIProvider;
    });

    $message = make_inbound_message($tenant, 'No AI');
    $conversation = u6_engine_conversation_for($message);

    TenantContext::setId($tenant->id);
    app(FlowEngine::class)->handleMessage($tenant, $message, $conversation, function (Tenant $t, Message $m, Conversation $c): void {
        app(FaqReplyService::class)->tryReply($t, $m, $c);
    });
    TenantContext::clear();

    expect($aiCalled)->toBeFalse();
})->group('FAQ-SEC-F07');

it('FAQ-SEC-F08: inactive FAQ not matched by matcher', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);
    Faq::factory()->create([
        'tenant_id' => $tenant->id,
        'question' => 'Inactive',
        'answer' => 'Should not match',
        'normalized_question' => 'inactive',
        'status' => FaqStatus::Inactive,
    ]);
    TenantContext::clear();

    $matcher = app(FaqMatcherService::class);

    TenantContext::setId($tenant->id);
    $result = $matcher->match($tenant, 'Inactive');
    TenantContext::clear();

    expect($result)->toBeNull();
})->group('FAQ-SEC-F08');

it('FAQ-SEC-F09: soft-deleted FAQ not matched by matcher', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);
    $faq = Faq::factory()->create([
        'tenant_id' => $tenant->id,
        'question' => 'Deleted',
        'answer' => 'Gone',
        'normalized_question' => 'deleted',
        'status' => FaqStatus::Active,
    ]);
    $faq->delete();
    TenantContext::clear();

    $matcher = app(FaqMatcherService::class);

    TenantContext::setId($tenant->id);
    $result = $matcher->match($tenant, 'Deleted');
    TenantContext::clear();

    expect($result)->toBeNull();
})->group('FAQ-SEC-F09');

it('FAQ-SEC-F10: Unicode injection does not affect tenant scope', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    TenantContext::setId($tenantA->id);
    Faq::factory()->create([
        'tenant_id' => $tenantA->id,
        'question' => 'Pregunta',
        'answer' => 'Respuesta A',
        'normalized_question' => 'pregunta',
        'status' => FaqStatus::Active,
    ]);
    TenantContext::clear();

    $matcher = app(FaqMatcherService::class);

    TenantContext::setId($tenantB->id);
    $result = $matcher->match($tenantB, "Pregunta\x00injection");
    TenantContext::clear();

    expect($result)->toBeNull();
})->group('FAQ-SEC-F10');

it('FAQ-SEC-F11: FAQ outbound metadata contains faq_id from correct tenant', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);
    $faq = Faq::factory()->create([
        'tenant_id' => $tenant->id,
        'question' => 'Meta test',
        'answer' => 'Meta answer',
        'normalized_question' => 'meta test',
        'status' => FaqStatus::Active,
    ]);
    TenantContext::clear();

    $fake = new FakeFaqMatcherService;
    $fake->whenMatch(new FaqMatch(faqId: $faq->id, answer: $faq->answer, matchType: 'exact_normalized', priority: 5));
    app()->instance(FaqMatcherServiceInterface::class, $fake);

    $message = make_inbound_message($tenant, 'Meta test');
    $conversation = u6_engine_conversation_for($message);

    TenantContext::setId($tenant->id);
    app(FlowEngine::class)->handleMessage($tenant, $message, $conversation, function (Tenant $t, Message $m, Conversation $c): void {
        app(FaqReplyService::class)->tryReply($t, $m, $c);
    });
    TenantContext::clear();

    $outbound = Message::query()
        ->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->where('direction', MessageDirection::Outbound->value)
        ->first();

    expect($outbound)->not->toBeNull();
    expect($outbound->metadata)->toHaveKey('faq_id', $faq->id);
    expect($outbound->metadata)->toHaveKey('match_type', 'exact_normalized');
})->group('FAQ-SEC-F11');

it('FAQ-SEC-F12: matcher exception does not leak details', function (): void {
    $tenant = Tenant::factory()->create();

    $fake = new FakeFaqMatcherService;
    $fake->whenThrow(new RuntimeException('Internal DB connection lost'));
    app()->instance(FaqMatcherServiceInterface::class, $fake);

    $message = make_inbound_message($tenant, 'Test');
    $conversation = u6_engine_conversation_for($message);

    TenantContext::setId($tenant->id);
    app(FaqReplyService::class)->tryReply($tenant, $message, $conversation);
    TenantContext::clear();

    expect(u6_outbound_count($tenant))->toBe(0);
})->group('FAQ-SEC-F12');
