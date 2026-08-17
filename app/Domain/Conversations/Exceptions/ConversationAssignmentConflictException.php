<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Exceptions;

use DomainException;

/**
 * Conflictos de asignación detectados bajo el lock de conversación (409).
 */
final class ConversationAssignmentConflictException extends DomainException
{
    public const HTTP_STATUS = 409;

    private function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function alreadyAssigned(): self
    {
        return new self(
            'CONVERSATION_ALREADY_ASSIGNED',
            'La conversación ya fue asignada a otro agente.',
        );
    }

    public static function notAssigned(): self
    {
        return new self(
            'CONVERSATION_NOT_ASSIGNED',
            'La conversación no tiene un agente para transferir.',
        );
    }

    public static function sameTransferAgent(): self
    {
        return new self(
            'CONVERSATION_TRANSFER_SAME_AGENT',
            'La conversación ya está asignada al agente destino.',
        );
    }

    public static function notAwaitingHandoff(): self
    {
        return new self(
            'CONVERSATION_NOT_AWAITING_HANDOFF',
            'La conversación no está esperando atención humana.',
        );
    }

    public static function inconsistent(): self
    {
        return new self(
            'CONVERSATION_ASSIGNMENT_INCONSISTENT',
            'Las proyecciones de asignación de la conversación son inconsistentes.',
        );
    }

    public static function busy(): self
    {
        return new self(
            'CONVERSATION_ASSIGNMENT_BUSY',
            'La conversación está siendo modificada; intente nuevamente.',
        );
    }
}
