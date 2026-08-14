<?php

declare(strict_types=1);

namespace App\Domain\Flows\Enums;

/**
 * Operadores de condición para nodos `condition` (FASE 11).
 *
 * Evaluados por `ConditionEvaluator` sobre variables resueltas del flujo
 * (`{{custom.*}}`, `{{contact.*}}`, `{{business.*}}`, `{{conversation.id}}`).
 *
 * Los operadores sin valor (`exists`, `not_exists`, `is_empty`, `is_not_empty`)
 * NO requieren el campo `value` en la regla.
 */
enum FlowConditionOperator: string
{
    case Equals = 'equals';
    case NotEquals = 'not_equals';
    case Contains = 'contains';
    case NotContains = 'not_contains';
    case GreaterThan = 'greater_than';
    case LessThan = 'less_than';
    case GreaterOrEqual = 'greater_or_equal';
    case LessOrEqual = 'less_or_equal';
    case Exists = 'exists';
    case NotExists = 'not_exists';
    case IsEmpty = 'is_empty';
    case IsNotEmpty = 'is_not_empty';

    /**
     * Operadores que requieren el campo `value` en la regla.
     */
    public function needsValue(): bool
    {
        return ! in_array($this, [self::Exists, self::NotExists, self::IsEmpty, self::IsNotEmpty], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Equals => 'igual a',
            self::NotEquals => 'distinto de',
            self::Contains => 'contiene',
            self::NotContains => 'no contiene',
            self::GreaterThan => 'mayor que',
            self::LessThan => 'menor que',
            self::GreaterOrEqual => 'mayor o igual que',
            self::LessOrEqual => 'menor o igual que',
            self::Exists => 'existe',
            self::NotExists => 'no existe',
            self::IsEmpty => 'está vacío',
            self::IsNotEmpty => 'no está vacío',
        };
    }
}
