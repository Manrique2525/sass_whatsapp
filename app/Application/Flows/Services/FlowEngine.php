<?php

declare(strict_types=1);

namespace App\Application\Flows\Services;

use App\Domain\Conversations\Models\Conversation;
use App\Domain\Flows\Enums\FlowExecutionStatus;
use App\Domain\Flows\Enums\FlowNodeType;
use App\Domain\Flows\Enums\FlowStatus;
use App\Domain\Flows\Exceptions\FlowWebhookRequestFailedException;
use App\Domain\Flows\Exceptions\WebhookUrlBlockedException;
use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Models\FlowConnection;
use App\Domain\Flows\Models\FlowExecution;
use App\Domain\Flows\Models\FlowNode;
use App\Domain\Flows\Models\Trigger;
use App\Domain\Flows\Services\TriggerMatcher;
use App\Domain\Flows\ValueObjects\NodeExecutionContext;
use App\Domain\Messages\Enums\MessageDirection;
use App\Domain\Messages\Models\Message;
use App\Domain\Tenants\Models\Tenant;
use App\Jobs\ContinueFlowExecution;
use Illuminate\Support\Collection;

/**
 * Motor de flujos (FASE 11, `docs/chatbot-engine.md` §4/§5).
 *
 * Determinista, reanudable e idempotente:
 * - UN único punto de entrada por conversación: `handleMessage` (mensaje
 *   entrante) y `continueExecution` (delay programado). Ambos corren bajo el
 *   lock Redis `lock:tenant:{id}:flow:{conversation_id}` (ADR-015/037).
 * - El ciclo avanza nodo a nodo; cada paso se persiste y se traza en
 *   `flow_execution_logs`. Solo `question`/`buttons` esperan respuesta del
 *   contacto (`waiting`); `delay` espera una continuación programada.
 * - Idempotencia: `last_inbound_message_id` es la barrera del motor (un mismo
 *   inbound reprocesado no avanza dos veces).
 * - Fallos transitorios del nodo `webhook` reintentan con backoff (máx 3);
 *   fallos permanentes marcan `failed`.
 *
 * Se ejecuta SIEMPRE dentro de un TenantContext ya establecido por el job
 * invocador (`TenantAwareJob`); el motor no lo crea ni lo limpia.
 */
final class FlowEngine
{
    public const MAX_TRANSIENT_RETRIES = 3;

    /** @var array<int, int> backoff (segundos) por intento de reintento */
    public const RETRY_BACKOFF = [5, 15, 30];

    public function __construct(
        private readonly FlowExecutionService $executions,
        private readonly NodeExecutorRegistry $registry,
        private readonly TriggerMatcher $matcher,
    ) {}

    /**
     * Punto de entrada por mensaje entrante (webhook job). Bajo lock: resuelve
     * trigger o reanuda la ejecución activa, y avanza el ciclo.
     */
    public function handleMessage(Tenant $tenant, Message $inbound, Conversation $conversation): void
    {
        $lock = $this->executions->conversationLock($tenant, $conversation->id);
        $lock->block(seconds: 10);

        try {
            $this->handleMessageLocked($tenant, $inbound, $conversation);
        } finally {
            $lock->release();
        }
    }

    /**
     * Punto de entrada por continuación programada. `$mode` indica el motivo:
     * `delay` (avanzar tras un nodo `delay`) o `retry` (re-ejecutar el nodo
     * actual tras un fallo transitorio). Bajo lock.
     */
    public function continueExecution(Tenant $tenant, FlowExecution $execution, string $mode = 'delay'): void
    {
        $lock = $this->executions->conversationLock($tenant, (string) $execution->conversation_id);
        $lock->block(seconds: 10);

        try {
            $this->continueExecutionLocked($tenant, $execution, $mode);
        } finally {
            $lock->release();
        }
    }

    private function handleMessageLocked(Tenant $tenant, Message $inbound, Conversation $conversation): void
    {
        $conversation->refresh();

        if ($conversation->bot_paused) {
            return;
        }

        $execution = $this->executions->findActive($conversation);

        if ($execution === null) {
            $flow = $this->matchFlow($tenant, $inbound, $conversation);

            if ($flow === null) {
                return;
            }

            $execution = $this->executions->start($flow, $conversation, $inbound);

            $this->run($tenant, $execution, $conversation, $inbound);

            return;
        }

        if ($execution->status === FlowExecutionStatus::Running) {
            // El ciclo ya está en curso (otro worker bajo lock): el inbound se
            // ignora; la ejecución activa continuará por sí misma.
            return;
        }

        if ($inbound->id === $execution->last_inbound_message_id) {
            return;
        }

        $currentNode = $execution->currentNode;

        if ($currentNode === null) {
            $this->executions->finish($execution, FlowExecutionStatus::Failed, 'execution.failed', [
                'reason' => 'missing_current_node',
            ]);

            return;
        }

        if ($currentNode->type === FlowNodeType::Question) {
            $this->resumeAfterAnswer($tenant, $execution, $conversation, $currentNode, $inbound);

            return;
        }

        if ($currentNode->type === FlowNodeType::Buttons) {
            if (! $this->matchesButton($currentNode, $inbound->body)) {
                $this->resendButtons($tenant, $execution, $conversation, $currentNode, $inbound);

                return;
            }

            $this->resumeAfterAnswer($tenant, $execution, $conversation, $currentNode, $inbound);

            return;
        }

        if ($currentNode->type === FlowNodeType::Delay) {
            // Esperando la continuación programada (`ContinueFlowExecution`).
            return;
        }

        $this->executions->finish($execution, FlowExecutionStatus::Failed, 'execution.failed', [
            'reason' => 'invalid_waiting_state',
            'node_type' => $currentNode->type->value,
        ]);
    }

    private function continueExecutionLocked(Tenant $tenant, FlowExecution $execution, string $mode): void
    {
        $execution->refresh();

        if (! $execution->status->isActive()) {
            return;
        }

        $conversation = $execution->conversation;

        if ($conversation->bot_paused) {
            return;
        }

        $currentNode = $execution->currentNode;

        if ($currentNode === null) {
            return;
        }

        if ($currentNode->type->isWaitingType()) {
            // No debe ocurrir: solo `delay`/`retry` programan continuaciones.
            return;
        }

        if ($mode === 'retry') {
            $this->run($tenant, $execution, $conversation, null);

            return;
        }

        if ($currentNode->type !== FlowNodeType::Delay) {
            // Modo delay pero el nodo actual no es un delay: estado inconsistente.
            return;
        }

        $next = $this->successorNodeId($execution, $currentNode, null);

        if ($next === null) {
            $this->executions->finish($execution, FlowExecutionStatus::Failed, 'execution.failed', [
                'reason' => 'delay_without_successor',
            ]);

            return;
        }

        $this->executions->advance($execution, [
            'current_node_id' => $next,
            'status' => FlowExecutionStatus::Running,
        ], event: 'step_completed', nodeId: $currentNode->id, payload: ['next' => $next]);

        $this->run($tenant, $execution, $conversation, null);
    }

    /**
     * Captura la respuesta de un nodo `question`/`buttons` y reanuda el ciclo
     * desde el siguiente nodo.
     */
    private function resumeAfterAnswer(
        Tenant $tenant,
        FlowExecution $execution,
        Conversation $conversation,
        FlowNode $node,
        Message $inbound,
    ): void {
        if ($node->type === FlowNodeType::Question) {
            $field = trim((string) ($node->config['field'] ?? ''));

            if ($field !== '') {
                $variables = $execution->variables;
                $variables['custom'][$field] = $inbound->body;
                $execution->forceFill(['variables' => $variables])->save();
            }
        }

        $next = $this->successorNodeId($execution, $node, null);

        if ($next === null) {
            $this->executions->finish($execution, FlowExecutionStatus::Failed, 'execution.failed', [
                'reason' => 'waiting_node_without_successor',
            ]);

            return;
        }

        $this->executions->advance($execution, [
            'current_node_id' => $next,
            'status' => FlowExecutionStatus::Running,
            'last_inbound_message_id' => $inbound->id,
        ], event: 'step_completed', nodeId: $node->id, payload: ['next' => $next]);

        $this->run($tenant, $execution, $conversation, $inbound);
    }

    /**
     * Reenvía las opciones de un nodo `buttons` cuando la respuesta no
     * coincide con ninguna opción (se mantiene `waiting`). Registra la barrera
     * de idempotencia para no reenviar si el mismo inbound se reprocesa.
     */
    private function resendButtons(
        Tenant $tenant,
        FlowExecution $execution,
        Conversation $conversation,
        FlowNode $node,
        Message $inbound,
    ): void {
        $this->executions->log($execution, event: 'buttons_no_match', nodeId: $node->id, payload: [
            'inbound_id' => $inbound->id,
        ]);

        $execution->forceFill(['last_inbound_message_id' => $inbound->id])->save();

        $context = $this->buildContext($tenant, $node, $execution, $conversation, $inbound);

        $this->registry->for(FlowNodeType::Buttons)->execute($context);
    }

    /**
     * Ciclo principal: ejecuta nodos desde `current_node_id` hasta wait/delay/
     * terminal, guardado por `max_steps`. `$inbound` se usa para resolver
     * variables del contexto y fijar la barrera de idempotencia.
     */
    private function run(
        Tenant $tenant,
        FlowExecution $execution,
        Conversation $conversation,
        ?Message $inbound,
    ): void {
        $flow = $execution->flow;
        $flow->loadMissing(['nodes', 'connections']);

        /** @var Collection<string, FlowNode> $nodeMap */
        $nodeMap = $flow->nodes->keyBy('id');

        $outgoing = $flow->connections->groupBy('source_node_id');

        $maxSteps = (int) ($flow->config['max_steps'] ?? 50);
        $steps = 0;
        $currentId = $execution->current_node_id;

        while ($currentId !== null) {
            $steps++;

            if ($steps > $maxSteps) {
                $this->executions->finish($execution, FlowExecutionStatus::Failed, 'execution.failed', [
                    'reason' => 'max_steps_exceeded',
                ]);

                return;
            }

            $node = $nodeMap->get($currentId);

            if ($node === null) {
                $this->executions->finish($execution, FlowExecutionStatus::Failed, 'execution.failed', [
                    'reason' => 'node_not_found',
                ]);

                return;
            }

            $this->executions->log($execution, event: 'step_started', nodeId: $node->id, payload: [
                'type' => $node->type->value,
            ]);

            $context = $this->buildContext($tenant, $node, $execution, $conversation, $inbound);

            try {
                $result = $this->registry->for($node->type)->execute($context);
            } catch (WebhookUrlBlockedException $e) {
                $this->executions->finish($execution, FlowExecutionStatus::Failed, 'execution.failed', [
                    'reason' => 'webhook_blocked',
                    'error' => $e->getMessage(),
                ]);

                return;
            } catch (FlowWebhookRequestFailedException $e) {
                $this->scheduleRetry($tenant, $execution, $conversation, $node, $e->getMessage());

                return;
            }

            switch ($result->state) {
                case 'wait':
                    $this->executions->advance($execution, [
                        'status' => FlowExecutionStatus::Waiting,
                        'last_inbound_message_id' => $inbound?->id,
                    ], event: 'step_waiting', nodeId: $node->id);

                    return;

                case 'delay':
                    $this->executions->advance($execution, [
                        'status' => FlowExecutionStatus::Waiting,
                        'last_inbound_message_id' => $inbound?->id,
                    ], event: 'step_delay', nodeId: $node->id, payload: [
                        'seconds' => $result->delaySeconds,
                    ]);

                    dispatch(
                        (new ContinueFlowExecution($execution->id))
                            ->forTenant($tenant->id)
                            ->mode('delay')
                            ->delay(now()->addSeconds($result->delaySeconds)),
                    );

                    return;

                case 'terminal':
                    $this->executions->finish(
                        $execution,
                        FlowExecutionStatus::from((string) $result->terminalStatus),
                        $result->terminalStatus === FlowExecutionStatus::Failed->value ? 'execution.failed' : 'execution.completed',
                    );

                    return;

                case 'continue':
                    $next = $this->successorNodeId($execution, $node, $result->nextLabel);

                    if ($next === null) {
                        $this->executions->finish($execution, FlowExecutionStatus::Failed, 'execution.failed', [
                            'reason' => 'no_outgoing_connection',
                            'node' => $node->name,
                        ]);

                        return;
                    }

                    $this->executions->advance($execution, [
                        'current_node_id' => $next,
                        'status' => FlowExecutionStatus::Running,
                        'last_inbound_message_id' => $inbound?->id,
                    ], event: 'step_completed', nodeId: $node->id, payload: ['next' => $next]);

                    $currentId = $next;

                    break;
            }
        }
    }

    /**
     * Construye el contexto inmutable de un paso del motor.
     */
    private function buildContext(
        Tenant $tenant,
        FlowNode $node,
        FlowExecution $execution,
        Conversation $conversation,
        ?Message $inbound,
    ): NodeExecutionContext {
        $contact = $conversation->contact;
        $business = $tenant->businessProfile ?? $tenant->businessProfile()->make();

        return new NodeExecutionContext(
            tenant: $tenant,
            node: $node,
            execution: $execution,
            conversation: $conversation,
            contact: $contact,
            business: $business,
            custom: $execution->variables['custom'] ?? [],
            inboundMessage: $inbound,
        );
    }

    private function successorNodeId(FlowExecution $execution, FlowNode $node, ?string $label): ?string
    {
        $flow = $execution->flow;
        $flow->loadMissing(['nodes', 'connections']);

        $connection = $flow->connections
            ->filter(static fn (FlowConnection $edge): bool => $edge->source_node_id === $node->id)
            ->first(static fn (FlowConnection $edge): bool => $label === null
                ? ($edge->label === null || $edge->label === '')
                : $edge->label === $label);

        if ($connection === null && $label === null) {
            $connection = $flow->connections
                ->first(static fn (FlowConnection $edge): bool => $edge->source_node_id === $node->id);
        }

        return $connection?->target_node_id;
    }

    private function matchFlow(Tenant $tenant, Message $inbound, Conversation $conversation): ?Flow
    {
        $isFirstMessage = $conversation->messages()
            ->where('direction', MessageDirection::Inbound->value)
            ->count() === 1;

        $triggers = Trigger::query()
            ->where('active', true)
            ->whereHas('flow', function ($query): void {
                $query->where('status', FlowStatus::Published->value);
            })
            ->with('flow')
            ->get()
            ->filter(static fn (Trigger $trigger): bool => $trigger->type->isImplementedInPhaseEleven())
            ->values()
            ->all();

        $matched = $this->matcher->match($triggers, (string) $inbound->body, $isFirstMessage);

        if ($matched === null) {
            return null;
        }

        $flow = $matched->flow;

        if ($flow === null || $flow->status !== FlowStatus::Published) {
            return null;
        }

        return $flow;
    }

    private function scheduleRetry(
        Tenant $tenant,
        FlowExecution $execution,
        Conversation $conversation,
        FlowNode $node,
        string $error,
    ): void {
        $attempt = $execution->attempts + 1;
        $execution->forceFill(['attempts' => $attempt])->save();

        $this->executions->log($execution, event: 'step_retry', nodeId: $node->id, payload: [
            'attempt' => $attempt,
            'error' => $error,
        ]);

        if ($attempt > self::MAX_TRANSIENT_RETRIES) {
            $this->executions->finish($execution, FlowExecutionStatus::Failed, 'execution.failed', [
                'reason' => 'max_retries_exceeded',
                'error' => $error,
            ]);

            return;
        }

        $backoff = self::RETRY_BACKOFF[min($attempt, count(self::RETRY_BACKOFF)) - 1] ?? 30;

        dispatch(
            (new ContinueFlowExecution($execution->id))
                ->forTenant($tenant->id)
                ->mode('retry')
                ->delay(now()->addSeconds($backoff)),
        );
    }

    private function matchesButton(FlowNode $node, string $body): bool
    {
        $normalized = strtolower(trim($body));

        if ($normalized === '') {
            return false;
        }

        foreach ($node->config['buttons'] ?? [] as $button) {
            if (! is_array($button)) {
                continue;
            }

            $title = strtolower(trim((string) ($button['title'] ?? '')));
            $id = strtolower(trim((string) ($button['id'] ?? '')));

            if ($title !== '' && $title === $normalized) {
                return true;
            }

            if ($id !== '' && $id === $normalized) {
                return true;
            }
        }

        return false;
    }
}
