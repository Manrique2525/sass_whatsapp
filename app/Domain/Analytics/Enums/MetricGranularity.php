<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Enums;

/**
 * Granularidad de agregación de métricas (FASE 21 U1, ADR-077).
 *
 * Define las dimensiones temporales soportadas para analytics.
 * En U1 solo daily está implementado; weekly/monthly son contrato futuro.
 */
enum MetricGranularity: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Daily',
            self::Weekly => 'Weekly',
            self::Monthly => 'Monthly',
        };
    }
}
