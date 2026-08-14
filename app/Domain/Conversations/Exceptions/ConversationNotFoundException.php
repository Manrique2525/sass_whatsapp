<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Exceptions;

use DomainException;

/**
 * La conversación no existe o pertenece a otro tenant (404; mensaje genérico
 * que oculta la existencia cross-tenant, ADR-010/023).
 */
final class ConversationNotFoundException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Conversación no encontrada.');
    }
}
