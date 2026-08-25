<?php

declare(strict_types=1);

namespace App\Application\Flows\Services;

use App\Application\Audit\Services\AuditLogger;
use App\Application\Users\Services\AuthorizationService;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Billing\Contracts\UsageGuardInterface;
use App\Domain\Billing\Enums\UsageCategory;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Flows\Enums\FlowExecutionStatus;
use App\Domain\Flows\Enums\FlowNodeType;
use App\Domain\Flows\Exceptions\FlowExecutionInvalidStateException;
use App\Domain\Flows\Exceptions\FlowExecutionNotFoundException;
use App\Domain\Flows\Exceptions\FlowInvalidException;
use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Models\FlowExecution;
use App\Domain\Flows\Models\FlowExecutionLog;
use App\Domain\Messages\Enums\MessageDirection;
use App\Domain\Messages\Enums\MessageOrigin;
use App\Domain\Messages\Enums\MessageStatus;
use App\Domain\Messages\Models\Message;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\TenantPermission;
use App\Domain\Users\Models\User;
use App\Jobs\RecoverPendingWhatsAppMessage;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Persistencia y casos de uso de las ejecuciones de flujos (FASE 11, ADR-037).
 *
 * Dos planos:
 * - Motor (sin usuario): `start`, `advance`, `finish`, `log`, `findActive` se
 *   llaman desde `FlowEngine` bajo el lock de conversación.
 * - API (con usuario): `indexExecutions`, `showExecution`, `pause`, `resume`,
 *   `cancel` con permisos de la matriz (`flows.view` para lectura,
 *   `flows.manage` para mutar).
 *
 * Invariante: solo hay UNA ejecución activa por conversación (UNIQUE parcial).
 * Al terminar (completed/failed/handed_off) se limpia
 * `conversations.flow_execution_id`; al cancelar también (status `completed`).
 */
final class FlowExecutionService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly AuditLogger $auditLogger,
        private readonly ConversationLockContext $lockContext,
        private readonly UsageGuardInterface $usageGuard,
    ) {}

    public function conversationLock(Tenant $tenant, string $conversationId, int $seconds = 150): Lock
    {
        return Cache::lock(
            "lock:tenant:{$tenant->id}:flow:{$conversationId}",
            seconds: $seconds,
        );
    }

    public function findActive(Conversation $conversation): ?FlowExecution
    {
        if ($conversation->flow_execution_id === null) {
            return null;
        }

        /** @var FlowExecution|null $execution */
        $execution = FlowExecution::query()
            ->withoutTenantScope()
            ->where('tenant_id', $conversation->tenant_id)
            ->where('conversation_id', $conversation->id)
            ->whereKey($conversation->flow_execution_id)
            ->active()
            ->first();

        return $execution;
    }

    /**
     * Crea una ejecución activa para la conversación y la enlaza
     * (`conversations.flow_execution_id`). Solo invocable por el motor (bajo
     * lock). El `current_node_id` arranca en el nodo de inicio del flujo.
     */
    public function start(Flow $flow, Conversation $conversation, ?Message $inbound = null): FlowExecution
    {
        $startNode = $flow->startNode;

        if ($startNode === null) {
            throw new FlowInvalidException('El flujo no tiene nodo de inicio.');
        }

        $executionId = (string) Str::uuid();
        $idempotencyKey = "flow_execution:{$executionId}";

        $reservation = $this->usageGuard->reserve(
            tenant: Tenant::query()->find((string) $flow->tenant_id),
            category: UsageCategory::FlowExecutions,
            quantity: 1,
            idempotencyKey: $idempotencyKey,
            ttlSeconds: 300,
        );

        $execution = FlowExecution::query()->create([
            'id' => $executionId,
            'flow_id' => $flow->id,
            'conversation_id' => $conversation->id,
            'current_node_id' => $startNode->id,
            'status' => FlowExecutionStatus::Running,
            'variables' => ['custom' => []],
            'attempts' => 0,
            'last_inbound_message_id' => $inbound?->id,
        ]);

        if ($reservation !== null) {
            $this->usageGuard->commit($reservation);
        }

        $conversation->forceFill(['flow_execution_id' => $execution->id])->save();

        $this->log($execution, event: 'execution.started', nodeId: $startNode->id, payload: [
            'flow_id' => $flow->id,
            'trigger_inbound' => $inbound?->id,
        ]);

        $this->auditLogger->record(
            action: 'flow.execution_started',
            data: ['flow_id' => $flow->id, 'conversation_id' => $conversation->id],
            subjectType: FlowExecution::class,
            subjectId: $execution->id,
            tenantId: (string) $flow->tenant_id,
        );

        return $execution;
    }

    /**
     * Persiste el estado del execution tras un paso del motor y escribe la
     * traza. Solo invocable bajo lock.
     *
     * @param  array<string, mixed>  $changes  campos a actualizar
     * @param  array<string, mixed>  $payload
     */
    public function advance(FlowExecution $execution, array $changes, string $event, ?string $nodeId = null, array $payload = []): void
    {
        $execution->forceFill($changes)->save();

        $this->log($execution, event: $event, nodeId: $nodeId, payload: $payload);
    }

    /**
     * Termina la ejecución (completed/failed/handed_off), limpia el enlace de
     * la conversación y traza el final. Solo invocable bajo lock.
     *
     * @param  array<string, mixed>  $payload
     */
    public function finish(
        FlowExecution $execution,
        FlowExecutionStatus $status,
        string $event,
        array $payload = [],
    ): void {
        DB::transaction(function () use ($execution, $status, $event, $payload): void {
            $lockedExecution = FlowExecution::query()
                ->withoutTenantScope()
                ->where('tenant_id', $execution->tenant_id)
                ->whereKey($execution->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedExecution->status->isTerminal()) {
                return;
            }

            $conversation = Conversation::query()
                ->withoutTenantScope()
                ->where('tenant_id', $lockedExecution->tenant_id)
                ->whereKey($lockedExecution->conversation_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedExecution->forceFill(['status' => $status])->save();

            if ($conversation->flow_execution_id === $lockedExecution->id) {
                $conversation->forceFill(['flow_execution_id' => null])->save();
            }

            $this->log(
                $lockedExecution,
                event: $event,
                nodeId: $lockedExecution->current_node_id,
                payload: $payload,
            );

            $this->auditLogger->record(
                action: 'flow.execution_'.($status === FlowExecutionStatus::Completed ? 'completed' : ($status === FlowExecutionStatus::Failed ? 'failed' : 'handed_off')),
                data: ['execution_id' => $lockedExecution->id],
                subjectType: FlowExecution::class,
                subjectId: $lockedExecution->id,
                tenantId: (string) $lockedExecution->tenant_id,
            );
        });

        $execution->refresh();
    }

    /**
     * Repara el único gap tolerado por ADR-051: handoff comprometido y crash
     * antes de finalizar la execution. Debe llamarse bajo conversationLock.
     */
    public function finalizeCommittedHandoff(Conversation $conversation): bool
    {
        $execution = $this->findActive($conversation);

        if ($execution === null) {
            return false;
        }

        $execution->loadMissing('currentNode');

        if ($execution->currentNode?->type !== FlowNodeType::Human) {
            return false;
        }

        $committed = AuditLog::query()
            ->where('tenant_id', $conversation->tenant_id)
            ->where('action', 'flow.handoff')
            ->where('subject_type', Conversation::class)
            ->where('subject_id', $conversation->id)
            ->where('data->flow_execution_id', $execution->id)
            ->exists();

        if (! $committed) {
            return false;
        }

        $notice = Message::query()
            ->withoutTenantScope()
            ->where('tenant_id', $conversation->tenant_id)
            ->where('conversation_id', $conversation->id)
            ->where('direction', MessageDirection::Outbound->value)
            ->where('status', MessageStatus::Pending->value)
            ->where('metadata->origin', MessageOrigin::Handoff->value)
            ->where('metadata->flow_execution_id', $execution->id)
            ->first();

        if ($notice !== null) {
            dispatch(
                (new RecoverPendingWhatsAppMessage(
                    (string) $conversation->tenant_id,
                    $conversation->id,
                    $notice->id,
                ))->forTenant((string) $conversation->tenant_id),
            );
        }

        $this->finish($execution, FlowExecutionStatus::HandedOff, 'execution.handed_off');

        return true;
    }

    /**
     * Escribe una entrada append-only en `flow_execution_logs` con la secuencia
     * siguiente. Debe llamarse bajo el lock de conversación.
     *
     * @param  array<string, mixed>  $payload
     */
    public function log(FlowExecution $execution, string $event, ?string $nodeId = null, array $payload = []): FlowExecutionLog
    {
        $sequence = (int) FlowExecutionLog::query()
            ->where('execution_id', $execution->id)
            ->max('sequence');

        return FlowExecutionLog::query()->create([
            'execution_id' => $execution->id,
            'node_id' => $nodeId,
            'event' => $event,
            'payload' => $payload === [] ? null : $payload,
            'sequence' => $sequence + 1,
        ]);
    }

    // ------------------------------------------------------------ API (usuario)

    /**
     * @param  array{status?: string, flow_id?: string, chatbot_id?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, FlowExecution>
     */
    public function indexExecutions(User $user, Tenant $tenant, array $filters): LengthAwarePaginator
    {
        $this->authorization->authorize($user, TenantPermission::ViewFlows, $tenant);

        $query = FlowExecution::query()
            ->with(['flow.chatbot', 'conversation.contact'])
            ->where('tenant_id', $tenant->id);

        if (isset($filters['status']) && $filters['status'] !== '') {
            $query->where('status', (string) $filters['status']);
        }

        if (isset($filters['flow_id']) && $filters['flow_id'] !== '') {
            $query->where('flow_id', (string) $filters['flow_id']);
        }

        if (isset($filters['chatbot_id']) && $filters['chatbot_id'] !== '') {
            $query->whereHas('flow', fn ($q) => $q->where('chatbot_id', (string) $filters['chatbot_id']));
        }

        return $query->orderByDesc('created_at')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function showExecution(User $user, Tenant $tenant, string $executionId): FlowExecution
    {
        $this->authorization->authorize($user, TenantPermission::ViewFlows, $tenant);

        $execution = $this->findForTenant($tenant, $executionId);

        $execution->loadMissing(['flow.chatbot', 'conversation.contact', 'currentNode']);

        return $execution;
    }

    public function pause(User $user, Tenant $tenant, string $executionId): FlowExecution
    {
        $this->authorization->authorize($user, TenantPermission::ManageFlows, $tenant);

        $execution = $this->findForTenant($tenant, $executionId);

        $this->assertActive($execution);

        $execution->conversation->forceFill(['bot_paused' => true])->save();

        $this->log($execution, event: 'execution.paused');

        $this->auditLogger->record(
            action: 'flow.execution_paused',
            data: ['execution_id' => $execution->id],
            subjectType: FlowExecution::class,
            subjectId: $execution->id,
            tenantId: $tenant->id,
        );

        return $execution;
    }

    public function resume(User $user, Tenant $tenant, string $executionId): FlowExecution
    {
        $this->authorization->authorize($user, TenantPermission::ManageFlows, $tenant);

        $execution = $this->findForTenant($tenant, $executionId);
        $lock = $this->conversationLock($tenant, (string) $execution->conversation_id);

        try {
            $lock->block(seconds: 10);
        } catch (LockTimeoutException) {
            throw new FlowExecutionInvalidStateException(
                'La conversación está siendo modificada; intente nuevamente.',
            );
        }

        $this->lockContext->enter($tenant->id, (string) $execution->conversation_id, $lock);

        try {
            $execution->refresh();
            $conversation = $execution->conversation;

            if ($this->finalizeCommittedHandoff($conversation)) {
                throw new FlowExecutionInvalidStateException(
                    'La ejecución ya finalizó por handoff y no puede reanudarse.',
                );
            }

            $this->assertActive($execution);
            $conversation->forceFill(['bot_paused' => false])->save();
        } finally {
            $this->lockContext->leave($tenant->id, (string) $execution->conversation_id);
            $lock->release();
        }

        $this->log($execution, event: 'execution.resumed');

        $this->auditLogger->record(
            action: 'flow.execution_resumed',
            data: ['execution_id' => $execution->id],
            subjectType: FlowExecution::class,
            subjectId: $execution->id,
            tenantId: $tenant->id,
        );

        return $execution;
    }

    public function cancel(User $user, Tenant $tenant, string $executionId): FlowExecution
    {
        $this->authorization->authorize($user, TenantPermission::ManageFlows, $tenant);

        $execution = $this->findForTenant($tenant, $executionId);

        $this->assertActive($execution);

        $execution->forceFill(['status' => FlowExecutionStatus::Completed])->save();

        $conversation = $execution->conversation;

        if ($conversation->flow_execution_id === $execution->id) {
            $conversation->forceFill(['flow_execution_id' => null])->save();
        }

        $this->log($execution, event: 'execution.cancelled');

        $this->auditLogger->record(
            action: 'flow.execution_cancelled',
            data: ['execution_id' => $execution->id],
            subjectType: FlowExecution::class,
            subjectId: $execution->id,
            tenantId: $tenant->id,
        );

        return $execution;
    }

    // ------------------------------------------------------------- Internals

    private function assertActive(FlowExecution $execution): void
    {
        if (! $execution->status->isActive()) {
            throw new FlowExecutionInvalidStateException(sprintf(
                'La ejecución está en estado "%s" y no admite esta operación.',
                $execution->status->value,
            ));
        }
    }

    private function findForTenant(Tenant $tenant, string $executionId): FlowExecution
    {
        $execution = FlowExecution::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->whereKey($executionId)
            ->first();

        if ($execution === null) {
            throw new FlowExecutionNotFoundException;
        }

        return $execution;
    }
}
