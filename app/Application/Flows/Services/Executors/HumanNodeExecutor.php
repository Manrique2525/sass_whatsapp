<?php

declare(strict_types=1);

namespace App\Application\Flows\Services\Executors;

use App\Application\Audit\Services\AuditLogger;
use App\Domain\Conversations\Enums\ConversationStatus;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Flows\Contracts\NodeExecutorInterface;
use App\Domain\Flows\Enums\FlowExecutionStatus;
use App\Domain\Flows\Enums\FlowNodeType;
use App\Domain\Flows\ValueObjects\NodeExecutionContext;
use App\Domain\Flows\ValueObjects\NodeExecutionResult;

/**
 * Ejecutor del nodo `human`: transfiere la conversación a atención humana.
 *
 * FASE 11 básico: pausa el bot (`conversations.bot_paused = true`), deja la
 * conversación en `open` (visible para agentes) y termina el execution en
 * `handed_off`. La cola/asignación a un agente específico, el aviso a agentes
 * y la UI de handoff llegan en FASE 15 (ver docs/chatbot-engine.md §7).
 */
final class HumanNodeExecutor implements NodeExecutorInterface
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    public function supports(): FlowNodeType
    {
        return FlowNodeType::Human;
    }

    public function execute(NodeExecutionContext $context): NodeExecutionResult
    {
        $conversation = $context->conversation;

        $conversation->forceFill([
            'bot_paused' => true,
            'status' => ConversationStatus::Open->value,
        ])->save();

        $this->auditLogger->record(
            action: 'flow.handoff',
            data: [
                'flow_execution_id' => $context->execution->id,
                'reason' => 'human_node',
            ],
            subjectType: Conversation::class,
            subjectId: $conversation->id,
            tenantId: $context->tenant->id,
        );

        return NodeExecutionResult::terminal(FlowExecutionStatus::HandedOff->value);
    }
}
