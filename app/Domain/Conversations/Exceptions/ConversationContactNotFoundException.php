<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Exceptions;

use DomainException;

/**
 * El contacto indicado al crear una conversación no existe o pertenece a otro
 * tenant (404; mensaje genérico que oculta la existencia, ADR-010/023).
 */
final class ConversationContactNotFoundException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Contacto no encontrado.');
    }
}
