<?php

declare(strict_types=1);

namespace App\Domain\Flows\ValueObjects;

/**
 * Resultado de un paso del motor (FASE 11, ADR-036).
 *
 * El ejecutor decide QUÉ hacer tras ejecutar su nodo; el motor decide CÓMO
 * aplicar la transición (siguiente arista, persistir estado, programar
 * continuaciones). Estados:
 *
 * - `continue`: avanzar por la arista `nextLabel` (o la única si es null).
 * - `wait`: esperar la respuesta del contacto (question/buttons). El motor
 *   deja el execution en `waiting` apuntando al mismo nodo.
 * - `delay`: esperar `delaySeconds` y continuar vía `ContinueFlowExecution`.
 * - `terminal`: terminar el execution con `terminalStatus`
 *   (completed|failed|handed_off).
 */
final readonly class NodeExecutionResult
{
    private function __construct(
        public string $state,
        public ?string $nextLabel = null,
        public ?int $delaySeconds = null,
        public ?string $terminalStatus = null,
    ) {}

    public static function continue(?string $nextLabel = null): self
    {
        return new self(state: 'continue', nextLabel: $nextLabel);
    }

    public static function wait(): self
    {
        return new self(state: 'wait');
    }

    public static function delay(int $seconds): self
    {
        return new self(state: 'delay', delaySeconds: max(1, $seconds));
    }

    public static function terminal(string $terminalStatus): self
    {
        return new self(state: 'terminal', terminalStatus: $terminalStatus);
    }
}
