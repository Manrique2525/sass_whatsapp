<?php

declare(strict_types=1);

namespace App\Application\Flows\ValueObjects;

/**
 * Resultado del procesamiento de un inbound por el FlowEngine (FASE 18 U4).
 *
 * Indica si el motor de flujos manejó el mensaje. Se usa para decidir
 * si el fallback FAQ debe ejecutarse (solo cuando handled = false).
 */
final readonly class FlowHandleResult
{
    public function __construct(
        public bool $handled,
    ) {}
}
