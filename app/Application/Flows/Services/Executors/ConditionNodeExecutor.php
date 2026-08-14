<?php

declare(strict_types=1);

namespace App\Application\Flows\Services\Executors;

use App\Domain\Flows\Contracts\NodeExecutorInterface;
use App\Domain\Flows\Enums\FlowNodeType;
use App\Domain\Flows\Services\ConditionEvaluator;
use App\Domain\Flows\ValueObjects\NodeExecutionContext;
use App\Domain\Flows\ValueObjects\NodeExecutionResult;

/**
 * Ejecutor del nodo `condition`: evalúa las reglas contra las variables
 * disponibles y avanza por la arista `true` o `false`.
 *
 * La evaluación es pura (`ConditionEvaluator`, AND implícito entre reglas).
 * El mapa de variables usa claves dotted (`contact.name`, `custom.<field>`,
 * `business.<campo>`, `conversation.id`).
 */
final class ConditionNodeExecutor implements NodeExecutorInterface
{
    public function __construct(
        private readonly ConditionEvaluator $evaluator,
    ) {}

    public function supports(): FlowNodeType
    {
        return FlowNodeType::Condition;
    }

    public function execute(NodeExecutionContext $context): NodeExecutionResult
    {
        $config = $context->node->config ?? [];
        $rules = is_array($config['rules'] ?? null) ? $config['rules'] : [];
        $variables = $this->buildVariables($context);

        $result = $this->evaluator->evaluate($variables, $rules);

        return NodeExecutionResult::continue($result ? 'true' : 'false');
    }

    /**
     * @return array<string, mixed>
     */
    private function buildVariables(NodeExecutionContext $context): array
    {
        $contact = $context->contact;
        $business = $context->business;

        $variables = [
            'contact.name' => $contact->name,
            'contact.phone' => $contact->phone,
            'contact.email' => $contact->email,
            'contact.metadata' => $contact->metadata,
            'conversation.id' => $context->conversation->id,
            'business.name' => $business->name,
            'business.description' => $business->description,
            'business.category' => $business->category,
            'business.address' => $business->address,
            'business.website' => $business->website,
            'business.email' => $business->email,
            'business.phone' => $business->phone,
        ];

        foreach ($context->custom as $key => $value) {
            $variables['custom.'.$key] = $value;
        }

        return $variables;
    }
}
