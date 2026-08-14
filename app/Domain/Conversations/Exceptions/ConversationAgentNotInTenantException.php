<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Exceptions;

use DomainException;

/**
 * El agente indicado para asignar/transferir una conversación no es miembro
 * ACTIVO del tenant (422). El `code` `AGENT_NOT_IN_TENANT` permite al
 * frontend distinguir el error sin depender del mensaje.
 */
final class ConversationAgentNotInTenantException extends DomainException
{
    public const ERROR_CODE = 'AGENT_NOT_IN_TENANT';

    public const HTTP_STATUS = 422;

    public function __construct()
    {
        parent::__construct('El agente indicado no es miembro activo de este tenant.');
    }
}
