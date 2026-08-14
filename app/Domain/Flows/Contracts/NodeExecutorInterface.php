<?php

declare(strict_types=1);

namespace App\Domain\Flows\Contracts;

use App\Domain\Flows\Enums\FlowNodeType;
use App\Domain\Flows\ValueObjects\NodeExecutionContext;
use App\Domain\Flows\ValueObjects\NodeExecutionResult;

/**
 * Ejecuta un nodo del flujo (FASE 11, ADR-036).
 *
 * Contrato del dominio: cada tipo de nodo tiene SU ejecutor. El ejecutor NO
 * decide el siguiente nodo (eso lo hace el motor según aristas/labels) y NUNCA
 * llama al provider de WhatsApp directamente: envía vía `MessageService`.
 *
 * El nodo `ai` NO tiene ejecutor en FASE 11 (bloqueado en `FlowValidator`,
 * reservado para FASE 16). No debe existir un ejecutor vacío/falso para él.
 */
interface NodeExecutorInterface
{
    /**
     * Tipo de nodo que soporta este ejecutor.
     */
    public function supports(): FlowNodeType;

    /**
     * Ejecuta el paso del nodo dentro del ciclo del motor.
     */
    public function execute(NodeExecutionContext $context): NodeExecutionResult;
}
