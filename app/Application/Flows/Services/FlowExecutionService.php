<?php

declare(strict_types=1);

namespace App\Application\Flows\Services;

use App\Application\Audit\Services\AuditLogger;
use App\Application\Users\Services\AuthorizationService;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Flows\Enums\FlowExecutionStatus;
use App\Domain\Flows\Exceptions\FlowExecutionInvalidStateException;
use App\Domain\Flows\Exceptions\FlowExecutionNotFoundException;
use App\Domain\Flows\Exceptions\FlowInvalidException;
use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Models\FlowExecution;
use App\Domain\Flows\Models\FlowExecutionLog;
use App\Domain\Messages\Models\Message;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\TenantPermission;
use App\Domain\Users\Models\User;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;

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
    ) {}

    public function conversationLock(Tenant $tenant, string $conversationId): Lock
    {
        return Cache::lock(
            "lock:tenant:{$tenant->id}:flow:{$conversationId}",
            seconds: 30,
        );
    }

    public function findActive(Conversation $conversation): ?FlowExecution
    {
        if ($conversation->flow_execution_id === null) {
            return null;
        }

        /** @var FlowExecution|null $execution */
        $execution = FlowExecution::query()
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

        $execution = FlowExecution::query()->create([
            'flow_id' => $flow->id,
            'conversation_id' => $conversation->id,
            'current_node_id' => $startNode->id,
            'status' => FlowExecutionStatus::Running,
            'variables' => ['custom' => []],
            'attempts' => 0,
            'last_inbound_message_id' => $inbound?->id,
        ]);

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
        $execution->forceFill(['status' => $status])->save();

        $conversation = $execution->conversation;

        if ($conversation->flow_execution_id === $execution->id) {
            $conversation->forceFill(['flow_execution_id' => null])->save();
        }

        $this->log($execution, event: $event, nodeId: $execution->current_node_id, payload: $payload);

        $this->auditLogger->record(
            action: 'flow.execution_'.($status === FlowExecutionStatus::Completed ? 'completed' : ($status === FlowExecutionStatus::Failed ? 'failed' : 'handed_off')),
            data: ['execution_id' => $execution->id],
            subjectType: FlowExecution::class,
            subjectId: $execution->id,
            tenantId: (string) $execution->tenant_id,
        );
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

        $this->assertActive($execution);

        $execution->conversation->forceFill(['bot_paused' => false])->save();

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
