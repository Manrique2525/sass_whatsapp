<?php

declare(strict_types=1);

namespace App\Application\Faq\Services;

use App\Application\Audit\Services\AuditLogger;
use App\Application\Faq\Contracts\FaqMatcherServiceInterface;
use App\Application\Messages\Services\MessageService;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Messages\Enums\MessageOrigin;
use App\Domain\Messages\Enums\MessageType;
use App\Domain\Messages\Models\Message;
use App\Domain\Tenants\Models\Tenant;
use Illuminate\Support\Facades\Log;

/**
 * Fallback FAQ para inbound messages sin manejo de flow (FASE 18 U4).
 *
 * Ejecuta bajo el lock de conversación del FlowEngine, solo cuando el motor
 * determinó que ningún flow procesó el mensaje. Fail-open: si el matcher o
 * la creación del outbound fallan, se registra y se retorna sin romper el
 * pipeline.
 *
 * Preconditions (garantizadas por FlowEngine antes de invocar el callback):
 * - bot_paused = false
 * - No hay ejecución activa de flow
 * - Ningún trigger de flow hizo match
 * - El inbound fue persistido (created = true)
 */
final class FaqReplyService
{
    public function __construct(
        private readonly FaqMatcherServiceInterface $matcher,
        private readonly MessageService $messageService,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Intenta responder un inbound con una FAQ match. Fail-open: cualquier
     * excepción se registra y se retorna sin efecto.
     */
    public function tryReply(Tenant $tenant, Message $inbound, Conversation $conversation): void
    {
        try {
            $this->attemptReply($tenant, $inbound, $conversation);
        } catch (\Throwable $e) {
            Log::warning('faq.reply.failed', [
                'tenant_id' => $tenant->id,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function attemptReply(Tenant $tenant, Message $inbound, Conversation $conversation): void
    {
        // Defense-in-depth: bot_paused
        if ($conversation->bot_paused) {
            return;
        }

        // Solo mensajes textuales (defense-in-depth)
        if ($inbound->type !== MessageType::Text) {
            return;
        }

        // Body vacío: evitar llamada innecesaria al matcher
        $body = $inbound->body ?? '';
        if ($body === '') {
            return;
        }

        $match = $this->matcher->match($tenant, $body);

        if ($match === null) {
            return;
        }

        $outbound = $this->messageService->createOutbound(
            tenant: $tenant,
            conversation: $conversation,
            body: $match->answer,
            origin: MessageOrigin::Automation,
            metadata: [
                'faq_id' => $match->faqId,
                'match_type' => $match->matchType,
            ],
        );

        $this->auditLogger->record(
            action: 'faq.matched',
            data: [
                'tenant_id' => $tenant->id,
                'conversation_id' => $conversation->id,
                'message_id' => $outbound->id,
                'faq_id' => $match->faqId,
                'match_type' => $match->matchType,
                'priority' => $match->priority,
            ],
            subjectType: Message::class,
            subjectId: $outbound->id,
            tenantId: $tenant->id,
        );
    }
}
