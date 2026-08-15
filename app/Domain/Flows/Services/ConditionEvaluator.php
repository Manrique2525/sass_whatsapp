<?php

declare(strict_types=1);

namespace App\Domain\Flows\Services;

use App\Domain\Flows\Enums\FlowConditionOperator;
use App\Domain\Flows\Enums\VariableType;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Evaluador de condiciones de nodos `condition` (FASE 11, extendido en FASE 13).
 *
 * Las reglas (`config.rules`) tienen forma `{field, operator, value?, not?}` y
 * se evalúan sobre un mapa plano de variables del flujo con claves "dotted"
 * (`custom.x`, `contact.name`, `business.name`, `conversation.id`).
 *
 * Semántica (FASE 13):
 * - `evaluate()`: TODAS las reglas deben cumplirse (AND).
 * - `evaluateAny()`: AL MENOS UNA regla debe cumplirse (OR).
 * - `evaluateGroup()`: respeta `group['match']` (`all` por defecto, `any`).
 * - `not: true` por regla niega el resultado de esa regla.
 * - Comparaciones numéricas coherentes (`'10' == 10`), booleanos siguiendo
 *   exactamente las reglas de `VariableType` y fechas ISO comparadas con
 *   Carbon. Sin eval() ni operadores construidos dinámicamente.
 *
 * Operadores sin valor (`exists`, `not_exists`, `is_empty`, `is_not_empty`)
 * no usan `value`.
 */
final class ConditionEvaluator
{
    /**
     * Evalúa todas las reglas (AND). Cada regla respeta su propio `not`.
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
     * Evalúa con OR: basta una regla verdadera.
     *
     * @param  array<string, mixed>  $variables
     * @param  list<array<string, mixed>>  $rules
     */
    public function evaluateAny(array $variables, array $rules): bool
    {
        foreach ($rules as $rule) {
            if ($this->evaluateRule($variables, $rule)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Evalúa un grupo `{match: 'all'|'any', rules: [...]}`.
     *
     * @param  array<string, mixed>  $variables
     * @param  array<string, mixed>  $group
     */
    public function evaluateGroup(array $variables, array $group): bool
    {
        $rules = is_array($group['rules'] ?? null) ? $group['rules'] : [];

        if ($rules === []) {
            return false;
        }

        return ($group['match'] ?? 'all') === 'any'
            ? $this->evaluateAny($variables, $rules)
            : $this->evaluate($variables, $rules);
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

        $result = match ($operator) {
            FlowConditionOperator::Equals => $this->equals($actual, $expected),
            FlowConditionOperator::NotEquals => ! $this->equals($actual, $expected),
            FlowConditionOperator::Contains => $this->contains($actual, $expected),
            FlowConditionOperator::NotContains => ! $this->contains($actual, $expected),
            FlowConditionOperator::StartsWith => $this->startsWith($actual, $expected),
            FlowConditionOperator::EndsWith => $this->endsWith($actual, $expected),
            FlowConditionOperator::GreaterThan => $this->compare($actual, $expected, '>'),
            FlowConditionOperator::LessThan => $this->compare($actual, $expected, '<'),
            FlowConditionOperator::GreaterOrEqual => $this->compare($actual, $expected, '>='),
            FlowConditionOperator::LessOrEqual => $this->compare($actual, $expected, '<='),
            FlowConditionOperator::Exists => array_key_exists($field, $variables),
            FlowConditionOperator::NotExists => ! array_key_exists($field, $variables),
            FlowConditionOperator::IsEmpty => $this->isEmpty($actual),
            FlowConditionOperator::IsNotEmpty => ! $this->isEmpty($actual),
        };

        return ($rule['not'] ?? false) === true ? ! $result : $result;
    }

    private function equals(mixed $actual, mixed $expected): bool
    {
        if ($actual === null || $expected === null) {
            return $actual === $expected;
        }

        // Fechas ISO: comparación segura por instante con Carbon.
        if ($this->isIsoDate($actual) && $this->isIsoDate($expected)) {
            $a = $this->toCarbon($actual);
            $b = $this->toCarbon($expected);

            return $a !== null && $b !== null && $a->equalTo($b);
        }

        // Numéricos: comparación coherente ('10' == 10).
        if (is_numeric($actual) && is_numeric($expected)) {
            return (float) $actual === (float) $expected;
        }

        // Booleanos: exactamente las reglas de VariableType ('1'/'0'/'sí'/'no'...).
        $boolActual = VariableType::Boolean->coerce($actual);
        $boolExpected = VariableType::Boolean->coerce($expected);

        if ($boolActual->ok && $boolExpected->ok) {
            return $boolActual->value === $boolExpected->value;
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

    private function startsWith(mixed $actual, mixed $expected): bool
    {
        if (! is_scalar($actual) || ! is_scalar($expected)) {
            return false;
        }

        return str_starts_with((string) $actual, (string) $expected);
    }

    private function endsWith(mixed $actual, mixed $expected): bool
    {
        if (! is_scalar($actual) || ! is_scalar($expected)) {
            return false;
        }

        return str_ends_with((string) $actual, (string) $expected);
    }

    private function compare(mixed $actual, mixed $expected, string $operator): bool
    {
        // Fechas ISO: comparación segura por instante con Carbon.
        if ($this->isIsoDate($actual) && $this->isIsoDate($expected)) {
            $a = $this->toCarbon($actual);
            $b = $this->toCarbon($expected);

            if ($a === null || $b === null) {
                return false;
            }

            return match ($operator) {
                '>' => $a->gt($b),
                '<' => $a->lt($b),
                '>=' => $a->gte($b),
                '<=' => $a->lte($b),
                default => false,
            };
        }

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

    /**
     * Detecta valores con forma de fecha ISO (`Y-m-d` con opcional hora).
     */
    private function isIsoDate(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^\d{4}-\d{2}-\d{2}([T ].*)?$/', $value) === 1
            && $this->toCarbon($value) !== null;
    }

    private function toCarbon(mixed $value): ?Carbon
    {
        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
