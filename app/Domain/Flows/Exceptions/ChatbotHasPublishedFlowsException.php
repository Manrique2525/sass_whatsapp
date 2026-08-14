<?php

declare(strict_types=1);

namespace App\Domain\Flows\Exceptions;

use DomainException;

/**
 * Eliminar un chatbot con flujos `published` en curso está prohibido (409):
 * borrarlo dejaría ejecuciones huérfanas del negocio. El `code`
 * `CHATBOT_HAS_PUBLISHED_FLOWS` permite al frontend distinguir el error.
 */
final class ChatbotHasPublishedFlowsException extends DomainException
{
    public const ERROR_CODE = 'CHATBOT_HAS_PUBLISHED_FLOWS';

    public const HTTP_STATUS = 409;

    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
