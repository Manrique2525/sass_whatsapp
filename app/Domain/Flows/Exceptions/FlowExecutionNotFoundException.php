<?php

declare(strict_types=1);

namespace App\Domain\Flows\Exceptions;

use DomainException;

/**
 * La ejecución no existe o pertenece a otro tenant (404; mensaje genérico que
 * oculta la existencia cross-tenant, ADR-010/023).
 */
final class FlowExecutionNotFoundException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Ejecución no encontrada.');
    }
}
