<?php

declare(strict_types=1);

namespace App\Domain\Leads\Exceptions;

use DomainException;

/**
 * El lead no existe o pertenece a otro tenant (se mapea a 404 por el
 * controller; el mensaje genérico oculta la existencia cross-tenant).
 */
final class LeadNotFoundException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Lead no encontrado.');
    }
}
