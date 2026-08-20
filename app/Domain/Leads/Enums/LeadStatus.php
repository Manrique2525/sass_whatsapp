<?php

declare(strict_types=1);

namespace App\Domain\Leads\Enums;

/**
 * Estado de un lead (FASE 19, ADR-072).
 *
 * Lifecycle lineal simplificado:
 * - new       — recién creado, sin interacción
 * - contacted — primer contacto registrado
 * - qualified — criterio de calificación cumplido
 * - won       — oportunidad cerrada (terminal)
 * - lost      — oportunidad perdida (terminal, reabrible a new)
 *
 * La enforcement de transiciones pertenece a U2 (service layer).
 * En U1 el enum solo define valores y labels.
 */
enum LeadStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Qualified = 'qualified';
    case Won = 'won';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Contacted => 'Contacted',
            self::Qualified => 'Qualified',
            self::Won => 'Won',
            self::Lost => 'Lost',
        };
    }

    /**
     * Evalúa si la transición de estado es válida.
     *
     * Lifecycle lineal simplificado:
     *   new → contacted
     *   contacted → qualified, won, lost
     *   qualified → won, lost
     *   won → (terminal)
     *   lost → new (reabrir ciclo)
     */
    public function canTransitionTo(self $target): bool
    {
        if ($this === $target) {
            return false;
        }

        return match ($this) {
            self::New => in_array($target, [self::Contacted]),
            self::Contacted => in_array($target, [self::Qualified, self::Won, self::Lost]),
            self::Qualified => in_array($target, [self::Won, self::Lost]),
            self::Won => false,
            self::Lost => $target === self::New,
        };
    }
}
