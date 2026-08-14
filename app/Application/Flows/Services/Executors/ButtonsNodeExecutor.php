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
 * Ejecutor del nodo `buttons` (FASE 11 básico).
 *
 * Envía el prompt y las opciones como texto numerado vía
 * `MessageService::createOutbound` (el canal de salida de FASE 11 es texto;
 * los mensajes `interactive` de Meta requieren ampliar el provider y llegan
 * en FASE 12). Espera la respuesta: el contacto responde con el texto del
 * botón (o su id). TODO FASE 12: enviar botones nativos `interactive`.
 */
final class ButtonsNodeExecutor implements NodeExecutorInterface
{
    public function __construct(
        private readonly MessageService $messages,
        private readonly VariableResolver $resolver,
    ) {}

    public function supports(): FlowNodeType
    {
        return FlowNodeType::Buttons;
    }

    public function execute(NodeExecutionContext $context): NodeExecutionResult
    {
        $config = $context->node->config ?? [];
        $prompt = $this->resolver->resolve(
            (string) ($config['text'] ?? ''),
            $context->contact,
            $context->business,
            $context->conversation,
            $context->custom,
        );

        $lines = [$prompt];

        foreach ($config['buttons'] ?? [] as $index => $button) {
            if (! is_array($button)) {
                continue;
            }

            $title = trim((string) ($button['title'] ?? ''));
            $id = trim((string) ($button['id'] ?? ''));

            if ($title === '') {
                continue;
            }

            $lines[] = sprintf('%d. %s', $index + 1, $title);

            if ($id !== '' && $id !== $title) {
                $lines[] = sprintf('   (%s)', $id);
            }
        }

        $this->messages->createOutbound($context->tenant, $context->conversation, implode("\n", $lines));

        return NodeExecutionResult::wait();
    }
}
