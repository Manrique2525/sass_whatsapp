<?php

declare(strict_types=1);

namespace App\Domain\Flows\Exceptions;

use DomainException;

/**
 * El trigger no existe o pertenece a otro tenant (404; mensaje genérico que
 * oculta la existencia cross-tenant, ADR-010/023).
 */
final class TriggerNotFoundException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Trigger no encontrado.');
    }
}
