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
 * Ejecutor del nodo `message`: envía un mensaje de texto al contacto y avanza.
 *
 * El texto soporta variables `{{...}}` resueltas por `VariableResolver`. El
 * envío se delega en `MessageService::createOutbound` (única vía autorizada de
 * salida en FASE 11); nunca se llama al provider directamente.
 */
final class MessageNodeExecutor implements NodeExecutorInterface
{
    public function __construct(
        private readonly MessageService $messages,
        private readonly VariableResolver $resolver,
    ) {}

    public function supports(): FlowNodeType
    {
        return FlowNodeType::Message;
    }

    public function execute(NodeExecutionContext $context): NodeExecutionResult
    {
        $config = $context->node->config ?? [];
        $text = $this->resolver->resolve(
            (string) ($config['text'] ?? ''),
            $context->contact,
            $context->business,
            $context->conversation,
            $context->custom,
        );

        $this->messages->createOutbound($context->tenant, $context->conversation, $text);

        return NodeExecutionResult::continue();
    }
}
