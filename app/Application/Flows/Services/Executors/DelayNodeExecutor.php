<?php

declare(strict_types=1);

namespace App\Application\Flows\Services\Executors;

use App\Domain\Flows\Contracts\NodeExecutorInterface;
use App\Domain\Flows\Enums\FlowNodeType;
use App\Domain\Flows\ValueObjects\NodeExecutionContext;
use App\Domain\Flows\ValueObjects\NodeExecutionResult;

/**
 * Ejecutor del nodo `delay`: pausa el execution `delaySeconds` y continúa vía
 * `ContinueFlowExecution` (el motor lo programa con delay y backoff; el
 * contacto puede seguir escribiendo pero el flujo no avanza hasta que el
 * delay termina). `seconds` ya está validado (1..3600) por `FlowValidator`.
 */
final class DelayNodeExecutor implements NodeExecutorInterface
{
    public function supports(): FlowNodeType
    {
        return FlowNodeType::Delay;
    }

    public function execute(NodeExecutionContext $context): NodeExecutionResult
    {
        $config = $context->node->config ?? [];

        return NodeExecutionResult::delay((int) ($config['seconds'] ?? 1));
    }
}
