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
| FAQ Runtime Idempotency Tests (FASE 18 U4)
|--------------------------------------------------------------------------
|
| FAQ-IDEM-01..07 — Verifican que FaqReplyService es idempotente y maneja
| correctamente edge cases: matcher exception, body vacío, tipo no-text,
| etc. Corren en SQLite :memory:.
*/

function make_inbound_for_faq(Tenant $tenant, string $body, string $type = 'text'): Message
{
    $payload = [
        'id' => 'wamid-'.(string) Str::uuid(),
        'from' => '15550000001',
        'timestamp' => '1725000000',
        'type' => $type,
    ];

    if ($type === 'text') {
        $payload['text'] = ['body' => $body];
    }

    return app(MessageService::class)->handleInboundMessage($tenant, $payload)->message;
}

function get_conversation_for(Message $message): Conversation
{
    return Conversation::query()
        ->withoutTenantScope()
        ->whereKey($message->conversation_id)
        ->firstOrFail();
}

function outbound_count(Tenant $tenant): int
{
    return Message::query()
        ->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->where('direction', MessageDirection::Outbound->value)
        ->count();
}

function audit_count(Tenant $tenant, string $action): int
{
    return AuditLog::query()
        ->where('tenant_id', $tenant->id)
        ->where('action', $action)
        ->count();
}

it('FAQ-IDEM-01: FaqReplyService with matching FAQ creates outbound', function (): void {
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

    $service = new FaqReplyService(
        $fakeMatcher,
        app(MessageService::class),
        app(AuditLogger::class),
    );

    $inbound = make_inbound_for_faq($tenant, '¿Cuál es tu horario?');
    $conversation = get_conversation_for($inbound);

    $service->tryReply($tenant, $inbound, $conversation);

    expect(outbound_count($tenant))->toBe(1);
    expect(audit_count($tenant, 'faq.matched'))->toBe(1);
})->group('FAQ-IDEM-01');

it('FAQ-IDEM-02: FaqReplyService with no match creates no outbound', function (): void {
    $tenant = Tenant::factory()->create();

    $fakeMatcher = new FakeFaqMatcherService;
    $fakeMatcher->whenMatch(null);

    $service = new FaqReplyService(
        $fakeMatcher,
        app(MessageService::class),
        app(AuditLogger::class),
    );

    $inbound = make_inbound_for_faq($tenant, 'Algo que no matchea');
    $conversation = get_conversation_for($inbound);

    $service->tryReply($tenant, $inbound, $conversation);

    expect(outbound_count($tenant))->toBe(0);
    expect(audit_count($tenant, 'faq.matched'))->toBe(0);
})->group('FAQ-IDEM-02');

it('FAQ-IDEM-03: FaqReplyService matcher exception is caught (fail-open)', function (): void {
    $tenant = Tenant::factory()->create();

    $fakeMatcher = new FakeFaqMatcherService;
    $fakeMatcher->whenThrow(new RuntimeException('DB connection lost'));

    $service = new FaqReplyService(
        $fakeMatcher,
        app(MessageService::class),
        app(AuditLogger::class),
    );

    $inbound = make_inbound_for_faq($tenant, 'Test question');
    $conversation = get_conversation_for($inbound);

    // Should NOT throw
    $service->tryReply($tenant, $inbound, $conversation);

    expect(outbound_count($tenant))->toBe(0);
})->group('FAQ-IDEM-03');

it('FAQ-IDEM-04: FaqReplyService skips non-text inbound (defense-in-depth)', function (): void {
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

    $inbound = make_inbound_for_faq($tenant, 'image payload', 'image');
    $conversation = get_conversation_for($inbound);

    $service->tryReply($tenant, $inbound, $conversation);

    expect(outbound_count($tenant))->toBe(0);
    expect($fakeMatcher->matchCount())->toBe(0);
})->group('FAQ-IDEM-04');

it('FAQ-IDEM-05: FaqReplyService skips empty body (defense-in-depth)', function (): void {
    $tenant = Tenant::factory()->create();

    $fakeMatcher = new FakeFaqMatcherService;

    $service = new FaqReplyService(
        $fakeMatcher,
        app(MessageService::class),
        app(AuditLogger::class),
    );

    $inbound = make_inbound_for_faq($tenant, '');
    $conversation = get_conversation_for($inbound);

    $service->tryReply($tenant, $inbound, $conversation);

    expect($fakeMatcher->matchCount())->toBe(0);
    expect(outbound_count($tenant))->toBe(0);
})->group('FAQ-IDEM-05');

it('FAQ-IDEM-06: FaqReplyService skips when bot_paused (defense-in-depth)', function (): void {
    $tenant = Tenant::factory()->create();

    $fakeMatcher = new FakeFaqMatcherService;
    $fakeMatcher->whenMatch(new FaqMatch(
        faqId: 'test-id',
        answer: 'answer',
        matchType: 'exact_normalized',
        priority: 1,
    ));

    $service = new FaqReplyService(
        $fakeMatcher,
        app(MessageService::class),
        app(AuditLogger::class),
    );

    $inbound = make_inbound_for_faq($tenant, 'Test');
    $conversation = get_conversation_for($inbound);
    $conversation->forceFill(['bot_paused' => true])->save();

    $service->tryReply($tenant, $inbound, $conversation);

    expect($fakeMatcher->matchCount())->toBe(0);
    expect(outbound_count($tenant))->toBe(0);
})->group('FAQ-IDEM-06');

it('FAQ-IDEM-07: FaqReplyService outbound has faq_id and match_type in metadata', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);
    $faq = Faq::factory()->create([
        'tenant_id' => $tenant->id,
        'question' => '¿Cuál es tu horario?',
        'answer' => 'Lunes a viernes.',
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

    $service = new FaqReplyService(
        $fakeMatcher,
        app(MessageService::class),
        app(AuditLogger::class),
    );

    $inbound = make_inbound_for_faq($tenant, '¿Cuál es tu horario?');
    $conversation = get_conversation_for($inbound);

    $service->tryReply($tenant, $inbound, $conversation);

    $outbound = Message::query()
        ->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->where('direction', MessageDirection::Outbound->value)
        ->firstOrFail();

    expect($outbound->metadata['faq_id'])->toBe($faq->id);
    expect($outbound->metadata['match_type'])->toBe('exact_normalized');
    expect($outbound->body)->toBe('Lunes a viernes.');
})->group('FAQ-IDEM-07');
