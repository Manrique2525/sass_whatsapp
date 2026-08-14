<?php

declare(strict_types=1);

namespace App\Application\Flows\Services;

use App\Domain\Flows\Contracts\NodeExecutorInterface;
use App\Domain\Flows\Enums\FlowNodeType;
use RuntimeException;

/**
 * Registro de ejecutores de nodos (FASE 11, ADR-036).
 *
 * Mapea `FlowNodeType` -> ejecutor. Se registra en `AppServiceProvider` con
 * las 9 implementaciones de FASE 11. El nodo `ai` NO tiene ejecutor: un flujo
 * publicado nunca contiene un nodo `ai` (bloqueado en `FlowValidator`), por lo
 * que `for()` solo puede fallar por corrupción de datos (RuntimeException).
 */
final class NodeExecutorRegistry
{
    /**
     * @var array<string, NodeExecutorInterface>
     */
    private array $executors = [];

    /**
     * @param  iterable<NodeExecutorInterface>  $executors
     */
    public function __construct(iterable $executors)
    {
        foreach ($executors as $executor) {
            $this->executors[$executor->supports()->value] = $executor;
        }
    }

    public function for(FlowNodeType $type): NodeExecutorInterface
    {
        if (! isset($this->executors[$type->value])) {
            throw new RuntimeException("No hay ejecutor registrado para el tipo de nodo '{$type->value}'.");
        }

        return $this->executors[$type->value];
    }
}
