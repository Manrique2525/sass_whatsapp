<?php

declare(strict_types=1);

namespace App\Application\Flows\Services\Executors;

use App\Application\Conversations\Services\HumanHandoffService;
use App\Domain\Flows\Contracts\NodeExecutorInterface;
use App\Domain\Flows\Enums\FlowExecutionStatus;
use App\Domain\Flows\Enums\FlowNodeType;
use App\Domain\Flows\ValueObjects\NodeExecutionContext;
use App\Domain\Flows\ValueObjects\NodeExecutionResult;

/**
 * Ejecutor del nodo `human`: transfiere la conversación a atención humana.
 *
 * El runtime transaccional vive en HumanHandoffService; FlowEngine conserva el
 * lock Redis y finaliza la ejecución como `handed_off` después del commit.
 */
final class HumanNodeExecutor implements NodeExecutorInterface
{
    public function __construct(
        private readonly HumanHandoffService $handoffs,
    ) {}

    public function supports(): FlowNodeType
    {
        return FlowNodeType::Human;
    }

    public function execute(NodeExecutionContext $context): NodeExecutionResult
    {
        $handoffMessage = $context->node->config['handoff_message'] ?? null;

        $this->handoffs->handoff(
            $context->tenant,
            $context->conversation,
            $context->execution,
            is_string($handoffMessage) ? $handoffMessage : null,
        );

        return NodeExecutionResult::terminal(FlowExecutionStatus::HandedOff->value);
    }
}
