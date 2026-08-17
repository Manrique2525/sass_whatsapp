<?php

declare(strict_types=1);

namespace App\Application\Conversations\Services;

use App\Application\Audit\Services\AuditLogger;
use App\Application\Messages\Services\MessageService;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Conversations\Enums\ConversationStatus;
use App\Domain\Conversations\Exceptions\ConversationInvalidStateException;
use App\Domain\Conversations\Exceptions\ConversationNotFoundException;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Flows\Enums\FlowNodeType;
use App\Domain\Flows\Models\FlowExecution;
use App\Domain\Messages\Enums\MessageOrigin;
use App\Domain\Tenants\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Persiste el handoff solicitado por un nodo Human.
 *
 * FlowEngine ya mantiene el lock Redis de la conversación; este servicio no lo
 * readquiere y limita su trabajo a una transacción con row lock.
 */
final class HumanHandoffService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly MessageService $messages,
    ) {}

    public function handoff(
        Tenant $tenant,
        Conversation $conversation,
        FlowExecution $execution,
        ?string $handoffMessage,
    ): void {
        DB::transaction(function () use ($tenant, $conversation, $execution, $handoffMessage): void {
            $locked = Conversation::query()
                ->withoutTenantScope()
                ->where('tenant_id', $tenant->id)
                ->whereKey($conversation->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw new ConversationNotFoundException;
            }

            if ($execution->tenant_id !== $tenant->id || $execution->conversation_id !== $locked->id) {
                throw new \InvalidArgumentException('La execution no pertenece a la conversación del handoff.');
            }

            $alreadyApplied = AuditLog::query()
                ->where('tenant_id', $tenant->id)
                ->where('action', 'flow.handoff')
                ->where('subject_type', Conversation::class)
                ->where('subject_id', $locked->id)
                ->where('data->flow_execution_id', $execution->id)
                ->exists();

            if ($alreadyApplied) {
                return;
            }

            $execution->loadMissing('currentNode');

            if (! $execution->status->isActive() || $execution->currentNode?->type !== FlowNodeType::Human) {
                throw new \InvalidArgumentException('La execution no está activa en un nodo Human.');
            }

            if (! in_array($locked->status, [ConversationStatus::Open, ConversationStatus::Pending], true)) {
                throw new ConversationInvalidStateException(
                    'El handoff humano solo puede iniciarse en una conversación abierta o pendiente.',
                );
            }

            $locked->forceFill([
                'bot_paused' => true,
                'handoff_requested_at' => now(),
            ])->save();

            $blockedMessages = $this->messages->blockAutomationForHandoff($tenant, $locked);

            if ($handoffMessage !== null && trim($handoffMessage) !== '') {
                $this->messages->createOutbound(
                    $tenant,
                    $locked,
                    $handoffMessage,
                    MessageOrigin::Handoff,
                    metadata: ['flow_execution_id' => $execution->id],
                );
            }

            $this->auditLogger->record(
                action: 'flow.handoff',
                data: [
                    'flow_execution_id' => $execution->id,
                    'reason' => 'human_node',
                    'handoff_message_sent' => $handoffMessage !== null && trim($handoffMessage) !== '',
                    'blocked_automation_messages' => $blockedMessages,
                ],
                subjectType: Conversation::class,
                subjectId: $locked->id,
                tenantId: $tenant->id,
            );
        });

        $conversation->refresh();
    }
}
