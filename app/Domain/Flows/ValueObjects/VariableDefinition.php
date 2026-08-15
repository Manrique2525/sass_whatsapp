<?php

declare(strict_types=1);

namespace App\Domain\Flows\ValueObjects;

use App\Domain\Flows\Enums\VariableType;

/**
 * Definición de una variable del catálogo de un flujo (FASE 13, UNIDAD 1).
 *
 * El catálogo se DERIVA de los datos del flujo y del tenant
 * (`VariableCatalogService`) — no se persiste en ninguna tabla. `source`
 * describe el origen (para trazabilidad), `writable` indica si el motor puede
 * capturarla desde el contacto.
 */
final readonly class VariableDefinition
{
    public function __construct(
        public string $key,
        public string $label,
        public string $namespace,
        public string $source,
        public VariableType $type,
        public mixed $default,
        public bool $writable,
    ) {}
}
