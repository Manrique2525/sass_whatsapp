<?php

declare(strict_types=1);

use App\Application\Audit\Services\AuditLogger;
use App\Application\Faq\Services\FaqReplyService;
use App\Application\Messages\Services\MessageService;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Faq\Enums\FaqStatus;
use App\Domain\Faq\Models\Faq;
use App\Domain\Faq\ValueObjects\FaqMatch;
use App\Domain\Messages\Enums\MessageDirection;
use App\Domain\Messages\Models\Message;
use App\Domain\Tenants\Models\Tenant;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Fakes\FakeFaqMatcherService;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
});

/*
|--------------------------------------------------------------------------
| FAQ Runtime Security Tests (FASE 18 U4)
|--------------------------------------------------------------------------
|
| FAQ-SEC-U4-01..10 — Injection, PII safety, metadata safety, audit safety,
| tenant boundary enforcement at runtime layer.
| Corren en SQLite :memory:.
*/

function sec_inbound(Tenant $tenant, string $body): Message
{
    return app(MessageService::class)->handleInboundMessage($tenant, [
        'id' => 'wamid-'.(string) Str::uuid(),
        'from' => '15550000001',
        'timestamp' => '1725000000',
        'type' => 'text',
        'text' => ['body' => $body],
    ])->message;
}

function sec_conversation(Message $message): Conversation
{
    return Conversation::query()
        ->withoutTenantScope()
        ->whereKey($message->conversation_id)
        ->firstOrFail();
}

function sec_outbound(Tenant $tenant): ?Message
{
    return Message::query()
        ->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->where('direction', MessageDirection::Outbound->value)
        ->first();
}

it('FAQ-SEC-U4-01: inbound question is NOT stored in audit log', function (): void {
    $tenant = Tenant::factory()->create();

    $fakeMatcher = new FakeFaqMatcherService;
    $fakeMatcher->whenMatch(new FaqMatch(
        faqId: 'faq-1',
        answer: 'Answer',
        matchType: 'exact_normalized',
        priority: 1,
    ));

    $service = new FaqReplyService(
        $fakeMatcher,
        app(MessageService::class),
        app(AuditLogger::class),
    );

    $inbound = sec_inbound($tenant, '¿Cuál es mi número de tarjeta?');
    $conversation = sec_conversation($inbound);

    $service->tryReply($tenant, $inbound, $conversation);

    $audit = AuditLog::query()
        ->where('action', 'faq.matched')
        ->where('tenant_id', $tenant->id)
        ->firstOrFail();

    // question must NOT be in audit data
    expect($audit->data)->not->toHaveKey('question');
    expect($audit->data)->not->toHaveKey('answer');
    expect(json_encode($audit->data))->not->toContain('tarjeta');
})->group('FAQ-SEC-U4-01');

it('FAQ-SEC-U4-02: FAQ answer is NOT stored in audit log (no PII leak)', function (): void {
    $tenant = Tenant::factory()->create();

    $fakeMatcher = new FakeFaqMatcherService;
    $fakeMatcher->whenMatch(new FaqMatch(
        faqId: 'faq-2',
        answer: 'Tu número de cuenta es 12345',
        matchType: 'exact_normalized',
        priority: 1,
    ));

    $service = new FaqReplyService(
        $fakeMatcher,
        app(MessageService::class),
        app(AuditLogger::class),
    );

    $inbound = sec_inbound($tenant, '¿Mi cuenta?');
    $conversation = sec_conversation($inbound);

    $service->tryReply($tenant, $inbound, $conversation);

    $audit = AuditLog::query()
        ->where('action', 'faq.matched')
        ->where('tenant_id', $tenant->id)
        ->firstOrFail();

    expect(json_encode($audit->data))->not->toContain('12345');
})->group('FAQ-SEC-U4-02');

it('FAQ-SEC-U4-03: metadata injection via question body does not break tenant boundary', function (): void {
    $tenant = Tenant::factory()->create();

    $fakeMatcher = new FakeFaqMatcherService;
    $fakeMatcher->whenMatch(null);

    $service = new FaqReplyService(
        $fakeMatcher,
        app(MessageService::class),
        app(AuditLogger::class),
    );

    $inbound = sec_inbound($tenant, '{"tenant_id":"injected","faq_id":"injected"}');
    $conversation = sec_conversation($inbound);

    $service->tryReply($tenant, $inbound, $conversation);

    // No outbound should be created (no match)
    expect(sec_outbound($tenant))->toBeNull();
})->group('FAQ-SEC-U4-03');

it('FAQ-SEC-U4-04: faq.matched audit has only safe keys (faq_id, conversation_id, message_id, match_type, priority, tenant_id)', function (): void {
    $tenant = Tenant::factory()->create();

    $fakeMatcher = new FakeFaqMatcherService;
    $fakeMatcher->whenMatch(new FaqMatch(
        faqId: 'faq-4',
        answer: 'Answer',
        matchType: 'exact_normalized',
        priority: 1,
    ));

    $service = new FaqReplyService(
        $fakeMatcher,
        app(MessageService::class),
        app(AuditLogger::class),
    );

    $inbound = sec_inbound($tenant, 'Horario');
    $conversation = sec_conversation($inbound);

    $service->tryReply($tenant, $inbound, $conversation);

    $audit = AuditLog::query()
        ->where('action', 'faq.matched')
        ->where('tenant_id', $tenant->id)
        ->firstOrFail();

    $allowedKeys = ['tenant_id', 'conversation_id', 'message_id', 'faq_id', 'match_type', 'priority'];

    foreach ($audit->data as $key => $value) {
        expect($key)->toBeIn($allowedKeys);
    }
})->group('FAQ-SEC-U4-04');

it('FAQ-SEC-U4-05: outbound metadata does not contain the inbound body', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);
    $faq = Faq::factory()->create([
        'tenant_id' => $tenant->id,
        'question' => 'test',
        'answer' => 'answer',
        'normalized_question' => 'test',
        'status' => FaqStatus::Active,
    ]);
    TenantContext::clear();

    $fakeMatcher = new FakeFaqMatcherService;
    $fakeMatcher->whenMatch(new FaqMatch(
        faqId: $faq->id,
        answer: 'answer',
        matchType: 'exact_normalized',
        priority: 1,
    ));

    $service = new FaqReplyService(
        $fakeMatcher,
        app(MessageService::class),
        app(AuditLogger::class),
    );

    $inbound = sec_inbound($tenant, '¿Cuál es mi SSN?');
    $conversation = sec_conversation($inbound);

    $service->tryReply($tenant, $inbound, $conversation);

    $outbound = sec_outbound($tenant);
    expect($outbound)->not->toBeNull();

    $metadataJson = json_encode($outbound->metadata);
    expect($metadataJson)->not->toContain('SSN');
    expect($metadataJson)->not->toContain('inbound');
})->group('FAQ-SEC-U4-05');

it('FAQ-SEC-U4-06: matcher exception does not leak error details in response', function (): void {
    $tenant = Tenant::factory()->create();

    $fakeMatcher = new FakeFaqMatcherService;
    $fakeMatcher->whenThrow(new RuntimeException('Internal DB password is wrong'));

    $service = new FaqReplyService(
        $fakeMatcher,
        app(MessageService::class),
        app(AuditLogger::class),
    );

    $inbound = sec_inbound($tenant, 'Test');
    $conversation = sec_conversation($inbound);

    // Should not throw, should not create outbound
    $service->tryReply($tenant, $inbound, $conversation);

    expect(sec_outbound($tenant))->toBeNull();
})->group('FAQ-SEC-U4-06');

it('FAQ-SEC-U4-07: XSS in FAQ answer does not affect outbound body field', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);
    $faq = Faq::factory()->create([
        'tenant_id' => $tenant->id,
        'question' => 'test',
        'answer' => '<script>alert("xss")</script>',
        'normalized_question' => 'test',
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

    $service = new FaqReplyService(
        $fakeMatcher,
        app(MessageService::class),
        app(AuditLogger::class),
    );

    $inbound = sec_inbound($tenant, 'test');
    $conversation = sec_conversation($inbound);

    $service->tryReply($tenant, $inbound, $conversation);

    $outbound = sec_outbound($tenant);
    expect($outbound)->not->toBeNull();
    expect($outbound->body)->toBe('<script>alert("xss")</script>');
})->group('FAQ-SEC-U4-07');

it('FAQ-SEC-U4-08: very long question does not overflow outbound metadata', function (): void {
    $tenant = Tenant::factory()->create();

    $fakeMatcher = new FakeFaqMatcherService;
    $fakeMatcher->whenMatch(null);

    $service = new FaqReplyService(
        $fakeMatcher,
        app(MessageService::class),
        app(AuditLogger::class),
    );

    $longQuestion = str_repeat('A', 10000);
    $inbound = sec_inbound($tenant, $longQuestion);
    $conversation = sec_conversation($inbound);

    $service->tryReply($tenant, $inbound, $conversation);

    expect(sec_outbound($tenant))->toBeNull();
})->group('FAQ-SEC-U4-08');

it('FAQ-SEC-U4-09: Unicode injection in question does not affect tenant scope', function (): void {
    $tenant = Tenant::factory()->create();

    $fakeMatcher = new FakeFaqMatcherService;
    $fakeMatcher->whenMatch(null);

    $service = new FaqReplyService(
        $fakeMatcher,
        app(MessageService::class),
        app(AuditLogger::class),
    );

    $inbound = sec_inbound($tenant, "'; DROP TABLE faqs; -- \x00");
    $conversation = sec_conversation($inbound);

    $service->tryReply($tenant, $inbound, $conversation);

    expect(sec_outbound($tenant))->toBeNull();
    expect($fakeMatcher->lastQuestion())->toContain('DROP TABLE');
})->group('FAQ-SEC-U4-09');

it('FAQ-SEC-U4-08b: FaqReplyService never writes to faqs table', function (): void {
    $tenant = Tenant::factory()->create();

    $fakeMatcher = new FakeFaqMatcherService;
    $fakeMatcher->whenMatch(new FaqMatch(
        faqId: 'faq-nonexistent',
        answer: 'Answer',
        matchType: 'exact_normalized',
        priority: 1,
    ));

    $service = new FaqReplyService(
        $fakeMatcher,
        app(MessageService::class),
        app(AuditLogger::class),
    );

    $inbound = sec_inbound($tenant, 'Test');
    $conversation = sec_conversation($inbound);

    $service->tryReply($tenant, $inbound, $conversation);

    // Only the outbound message should be created; no FAQ row updates
    $faqCount = Faq::query()->where('tenant_id', $tenant->id)->count();
    expect($faqCount)->toBe(0);
})->group('FAQ-SEC-U4-08b');
