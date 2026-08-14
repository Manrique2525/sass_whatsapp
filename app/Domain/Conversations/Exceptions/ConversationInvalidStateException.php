<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Exceptions;

use DomainException;

/**
 * La transición de estado solicitada no está permitida por la máquina de
 * estados (409). El `code` `CONVERSATION_INVALID_STATE` permite al frontend
 * distinguir el error sin depender del mensaje.
 */
final class ConversationInvalidStateException extends DomainException
{
    public const ERROR_CODE = 'CONVERSATION_INVALID_STATE';

    public const HTTP_STATUS = 409;

    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
