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
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Fakes\FakeFaqMatcherService;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
});

/*
|--------------------------------------------------------------------------
| FAQ Runtime Multi-Tenancy Tests (FASE 18 U4)
|--------------------------------------------------------------------------
|
| FAQ-RUNTIME-MT01..06 — Verifican aislamiento de tenant en el runtime
| FAQ: el matcher solo ve FAQs de su tenant, el outbound se escribe con
| el tenant correcto, audit logs apuntan al tenant correcto.
| Corren en SQLite :memory:.
*/

function mt_inbound(Tenant $tenant, string $body): Message
{
    return app(MessageService::class)->handleInboundMessage($tenant, [
        'id' => 'wamid-'.(string) Str::uuid(),
        'from' => '15550000001',
        'timestamp' => '1725000000',
        'type' => 'text',
        'text' => ['body' => $body],
    ])->message;
}

function mt_conversation(Message $message): Conversation
{
    return Conversation::query()
        ->withoutTenantScope()
        ->whereKey($message->conversation_id)
        ->firstOrFail();
}

function mt_outbound_messages(Tenant $tenant): Collection
{
    return Message::query()
        ->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->where('direction', MessageDirection::Outbound->value)
        ->get();
}

it('FAQ-RUNTIME-MT01: tenant A FAQ matched → outbound tenant_id = A', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    TenantContext::setId($tenantA->id);
    Faq::factory()->create([
        'tenant_id' => $tenantA->id,
        'question' => 'Horario',
        'answer' => 'Horario de A',
        'normalized_question' => 'horario',
        'status' => FaqStatus::Active,
    ]);
    TenantContext::clear();

    $fakeMatcher = new FakeFaqMatcherService;
    $fakeMatcher->whenMatch(new FaqMatch(
        faqId: 'faq-a',
        answer: 'Horario de A',
        matchType: 'exact_normalized',
        priority: 1,
    ));

    $service = new FaqReplyService(
        $fakeMatcher,
        app(MessageService::class),
        app(AuditLogger::class),
    );

    $inbound = mt_inbound($tenantA, 'Horario');
    $conversation = mt_conversation($inbound);

    $service->tryReply($tenantA, $inbound, $conversation);

    $outboundsA = mt_outbound_messages($tenantA);
    $outboundsB = mt_outbound_messages($tenantB);

    expect($outboundsA)->toHaveCount(1);
    expect($outboundsB)->toHaveCount(0);
})->group('FAQ-RUNTIME-MT01');

it('FAQ-RUNTIME-MT02: tenant A FAQ does not leak to tenant B', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    TenantContext::setId($tenantA->id);
    Faq::factory()->create([
        'tenant_id' => $tenantA->id,
        'question' => 'Privado A',
        'answer' => 'Respuesta privada A',
        'normalized_question' => 'privado a',
        'status' => FaqStatus::Active,
    ]);
    TenantContext::clear();

    $fakeMatcher = new FakeFaqMatcherService;
    $fakeMatcher->whenMatch(null);

    $service = new FaqReplyService(
        $fakeMatcher,
        app(MessageService::class),
        app(AuditLogger::class),
    );

    $inbound = mt_inbound($tenantB, 'Privado A');
    $conversation = mt_conversation($inbound);

    $service->tryReply($tenantB, $inbound, $conversation);

    expect($fakeMatcher->lastTenant()->id)->toBe($tenantB->id);
    expect(mt_outbound_messages($tenantB))->toHaveCount(0);
})->group('FAQ-RUNTIME-MT02');

it('FAQ-RUNTIME-MT03: faq.matched audit uses correct tenant_id', function (): void {
    $tenant = Tenant::factory()->create();

    $fakeMatcher = new FakeFaqMatcherService;
    $fakeMatcher->whenMatch(new FaqMatch(
        faqId: 'faq-x',
        answer: 'Answer',
        matchType: 'exact_normalized',
        priority: 1,
    ));

    $service = new FaqReplyService(
        $fakeMatcher,
        app(MessageService::class),
        app(AuditLogger::class),
    );

    $inbound = mt_inbound($tenant, 'Test');
    $conversation = mt_conversation($inbound);

    $service->tryReply($tenant, $inbound, $conversation);

    $audit = AuditLog::query()
        ->where('action', 'faq.matched')
        ->where('tenant_id', $tenant->id)
        ->firstOrFail();

    expect($audit->data['tenant_id'])->toBe($tenant->id);
})->group('FAQ-RUNTIME-MT03');

it('FAQ-RUNTIME-MT04: faq.matched audit conversation_id matches', function (): void {
    $tenant = Tenant::factory()->create();

    $fakeMatcher = new FakeFaqMatcherService;
    $fakeMatcher->whenMatch(new FaqMatch(
        faqId: 'faq-y',
        answer: 'Answer',
        matchType: 'exact_normalized',
        priority: 1,
    ));

    $service = new FaqReplyService(
        $fakeMatcher,
        app(MessageService::class),
        app(AuditLogger::class),
    );

    $inbound = mt_inbound($tenant, 'Test');
    $conversation = mt_conversation($inbound);

    $service->tryReply($tenant, $inbound, $conversation);

    $audit = AuditLog::query()
        ->where('action', 'faq.matched')
        ->where('tenant_id', $tenant->id)
        ->firstOrFail();

    expect($audit->data['conversation_id'])->toBe($conversation->id);
})->group('FAQ-RUNTIME-MT04');

it('FAQ-RUNTIME-MT05: outbound message metadata contains faq_id from correct tenant', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    TenantContext::setId($tenantA->id);
    $faqA = Faq::factory()->create([
        'tenant_id' => $tenantA->id,
        'question' => 'Horario',
        'answer' => 'Horario A',
        'normalized_question' => 'horario',
        'status' => FaqStatus::Active,
    ]);
    TenantContext::clear();

    $fakeMatcher = new FakeFaqMatcherService;
    $fakeMatcher->whenMatch(new FaqMatch(
        faqId: $faqA->id,
        answer: 'Horario A',
        matchType: 'exact_normalized',
        priority: 1,
    ));

    $service = new FaqReplyService(
        $fakeMatcher,
        app(MessageService::class),
        app(AuditLogger::class),
    );

    $inbound = mt_inbound($tenantA, 'Horario');
    $conversation = mt_conversation($inbound);

    $service->tryReply($tenantA, $inbound, $conversation);

    $outbound = Message::query()
        ->withoutTenantScope()
        ->where('tenant_id', $tenantA->id)
        ->where('direction', MessageDirection::Outbound->value)
        ->firstOrFail();

    expect($outbound->metadata['faq_id'])->toBe($faqA->id);
})->group('FAQ-RUNTIME-MT05');

it('FAQ-RUNTIME-MT06: matcher exception audit includes tenant_id', function (): void {
    $tenant = Tenant::factory()->create();

    $fakeMatcher = new FakeFaqMatcherService;
    $fakeMatcher->whenThrow(new RuntimeException('DB error'));

    $service = new FaqReplyService(
        $fakeMatcher,
        app(MessageService::class),
        app(AuditLogger::class),
    );

    $inbound = mt_inbound($tenant, 'Test');
    $conversation = mt_conversation($inbound);

    // Should not throw (fail-open)
    $service->tryReply($tenant, $inbound, $conversation);

    expect(mt_outbound_messages($tenant))->toHaveCount(0);
})->group('FAQ-RUNTIME-MT06');
