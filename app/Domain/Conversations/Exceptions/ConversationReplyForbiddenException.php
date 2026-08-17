<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Exceptions;

use DomainException;

/**
 * La conversación no puede recibir una respuesta del actor autenticado.
 */
final class ConversationReplyForbiddenException extends DomainException
{
    public const ERROR_CODE = 'CONVERSATION_REPLY_FORBIDDEN';

    public const BUSY_ERROR_CODE = 'CONVERSATION_REPLY_BUSY';

    public const HTTP_STATUS = 403;

    public const BUSY_HTTP_STATUS = 409;

    private function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function notAssignedToActor(): self
    {
        return new self(
            self::ERROR_CODE,
            self::HTTP_STATUS,
            'La conversación no está asignada al agente autenticado.',
        );
    }

    public static function busy(): self
    {
        return new self(
            self::BUSY_ERROR_CODE,
            self::BUSY_HTTP_STATUS,
            'La conversación está siendo modificada; intente nuevamente.',
        );
    }
}
