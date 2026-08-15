<?php

declare(strict_types=1);

namespace App\Domain\Flows\ValueObjects;

/**
 * Resultado de la coerción de un valor a un `VariableType` (FASE 13, UNIDAD 1).
 *
 * La coerción NUNCA lanza excepciones: `ok=false` indica que el valor no pudo
 * convertirse al tipo declarado y el motor usa el `default` del nodo (o
 * descarta la captura). `value` solo es fiable cuando `ok` es `true`.
 */
final readonly class VariableCoercion
{
    public function __construct(
        public bool $ok,
        public mixed $value,
    ) {}
}
