<?php

declare(strict_types=1);

namespace App\Application\Flows\Services\Executors;

use App\Domain\Flows\Contracts\NodeExecutorInterface;
use App\Domain\Flows\Enums\FlowExecutionStatus;
use App\Domain\Flows\Enums\FlowNodeType;
use App\Domain\Flows\ValueObjects\NodeExecutionContext;
use App\Domain\Flows\ValueObjects\NodeExecutionResult;

/**
 * Ejecutor del nodo `end`: marca el execution como `completed`. No envía
 * mensajes; cualquier despedida es responsabilidad del último nodo `message`.
 */
final class EndNodeExecutor implements NodeExecutorInterface
{
    public function supports(): FlowNodeType
    {
        return FlowNodeType::End;
    }

    public function execute(NodeExecutionContext $context): NodeExecutionResult
    {
        return NodeExecutionResult::terminal(FlowExecutionStatus::Completed->value);
    }
}
