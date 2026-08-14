<?php

declare(strict_types=1);

namespace App\Domain\Flows\Services;

use App\Domain\Flows\Enums\FlowConditionOperator;

/**
 * Evaluador de condiciones de nodos `condition` (FASE 11).
 *
 * Las reglas (`config.rules`) tienen forma `{field, operator, value?}` y se
 * evalúan sobre un mapa plano de variables del flujo con claves "dotted"
 * (`custom.x`, `contact.name`, `business.name`, `conversation.id`).
 *
 * Semántica: TODAS las reglas deben cumplirse (AND). Operadores sin valor
 * (`exists`, `not_exists`, `is_empty`, `is_not_empty`) no usan `value`.
 */
final class ConditionEvaluator
{
    /**
     * Evalúa todas las reglas (AND).
     *
     * @param  array<string, mixed>  $variables
     * @param  list<array<string, mixed>>  $rules
     */
    public function evaluate(array $variables, array $rules): bool
    {
        if ($rules === []) {
            return false;
        }

        foreach ($rules as $rule) {
            if (! $this->evaluateRule($variables, $rule)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $variables
     * @param  array<string, mixed>  $rule
     */
    private function evaluateRule(array $variables, array $rule): bool
    {
        $field = (string) ($rule['field'] ?? '');
        $operator = FlowConditionOperator::tryFrom((string) ($rule['operator'] ?? ''));

        if ($field === '' || $operator === null) {
            return false;
        }

        $expected = $rule['value'] ?? null;
        $actual = array_key_exists($field, $variables) ? $variables[$field] : null;

        return match ($operator) {
            FlowConditionOperator::Equals => $this->equals($actual, $expected),
            FlowConditionOperator::NotEquals => ! $this->equals($actual, $expected),
            FlowConditionOperator::Contains => $this->contains($actual, $expected),
            FlowConditionOperator::NotContains => ! $this->contains($actual, $expected),
            FlowConditionOperator::GreaterThan => $this->numericCompare($actual, $expected, '>'),
            FlowConditionOperator::LessThan => $this->numericCompare($actual, $expected, '<'),
            FlowConditionOperator::GreaterOrEqual => $this->numericCompare($actual, $expected, '>='),
            FlowConditionOperator::LessOrEqual => $this->numericCompare($actual, $expected, '<='),
            FlowConditionOperator::Exists => array_key_exists($field, $variables),
            FlowConditionOperator::NotExists => ! array_key_exists($field, $variables),
            FlowConditionOperator::IsEmpty => $this->isEmpty($actual),
            FlowConditionOperator::IsNotEmpty => ! $this->isEmpty($actual),
        };
    }

    private function equals(mixed $actual, mixed $expected): bool
    {
        if ($actual === null || $expected === null) {
            return $actual === $expected;
        }

        if (is_numeric($actual) && is_numeric($expected)) {
            return (float) $actual === (float) $expected;
        }

        return (string) $actual === (string) $expected;
    }

    private function contains(mixed $actual, mixed $expected): bool
    {
        if (! is_scalar($actual) || ! is_scalar($expected)) {
            return false;
        }

        return str_contains((string) $actual, (string) $expected);
    }

    private function numericCompare(mixed $actual, mixed $expected, string $operator): bool
    {
        if (! is_numeric($actual) || ! is_numeric($expected)) {
            return false;
        }

        return match ($operator) {
            '>' => (float) $actual > (float) $expected,
            '<' => (float) $actual < (float) $expected,
            '>=' => (float) $actual >= (float) $expected,
            '<=' => (float) $actual <= (float) $expected,
            default => false,
        };
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [] || $value === false;
    }
}
