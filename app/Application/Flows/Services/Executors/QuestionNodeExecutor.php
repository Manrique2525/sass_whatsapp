<?php

declare(strict_types=1);

namespace App\Application\Flows\Services\Executors;

use App\Application\Messages\Services\MessageService;
use App\Domain\Flows\Contracts\NodeExecutorInterface;
use App\Domain\Flows\Enums\FlowNodeType;
use App\Domain\Flows\Services\VariableResolver;
use App\Domain\Flows\ValueObjects\NodeExecutionContext;
use App\Domain\Flows\ValueObjects\NodeExecutionResult;

/**
 * Ejecutor del nodo `question`: envía el prompt y espera la respuesta libre.
 *
 * La captura de la respuesta (guardarla en `custom.<field>`) la realiza el
 * motor al reanudar la ejecución con el siguiente inbound; este ejecutor solo
 * envía el prompt y deja el execution en `waiting`.
 */
final class QuestionNodeExecutor implements NodeExecutorInterface
{
    public function __construct(
        private readonly MessageService $messages,
        private readonly VariableResolver $resolver,
    ) {}

    public function supports(): FlowNodeType
    {
        return FlowNodeType::Question;
    }

    public function execute(NodeExecutionContext $context): NodeExecutionResult
    {
        $config = $context->node->config ?? [];
        $prompt = $this->resolver->resolve(
            (string) ($config['prompt'] ?? ''),
            $context->contact,
            $context->business,
            $context->conversation,
            $context->custom,
        );

        $this->messages->createOutbound($context->tenant, $context->conversation, $prompt);

        return NodeExecutionResult::wait();
    }
}
