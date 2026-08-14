<?php

declare(strict_types=1);

namespace App\Domain\Flows\Enums;

/**
 * Estado de una ejecución de flujo (FASE 11, ADR-037).
 *
 * Solo `running`/`waiting` son estados activos (una sola ejecución activa por
 * conversación, reforzada por el UNIQUE parcial de `flow_executions`):
 * - `running`: el motor está avanzando por nodos (transitorio, bajo el lock
 *   Redis de la conversación).
 * - `waiting`: esperando input del cliente (question/buttons) o la reanudación
 *   de un nodo asíncrono (delay).
 * - `completed`/`failed`/`handed_off`: terminales.
 *
 * `handed_off` marca la transferencia a un humano: el bot queda pausado
 * (`conversations.bot_paused = true`) hasta que un agente lo reanude.
 */
enum FlowExecutionStatus: string
{
    case Running = 'running';
    case Waiting = 'waiting';
    case Completed = 'completed';
    case Failed = 'failed';
    case HandedOff = 'handed_off';

    public function isActive(): bool
    {
        return in_array($this, [self::Running, self::Waiting], true);
    }

    public function isTerminal(): bool
    {
        return ! $this->isActive();
    }

    public function label(): string
    {
        return match ($this) {
            self::Running => 'En ejecución',
            self::Waiting => 'Esperando input',
            self::Completed => 'Completada',
            self::Failed => 'Fallida',
            self::HandedOff => 'Transferida a humano',
        };
    }
}
