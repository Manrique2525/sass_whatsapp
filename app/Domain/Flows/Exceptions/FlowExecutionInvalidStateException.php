<?php

declare(strict_types=1);

namespace App\Domain\Flows\Exceptions;

use DomainException;

/**
 * Acción sobre una ejecución en un estado no permitido (409), p. ej. pausar/
 * reanudar/cancelar una ejecución ya terminal. El `code`
 * `EXECUTION_INVALID_STATE` permite al frontend distinguir el error.
 */
final class FlowExecutionInvalidStateException extends DomainException
{
    public const ERROR_CODE = 'EXECUTION_INVALID_STATE';

    public const HTTP_STATUS = 409;

    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
