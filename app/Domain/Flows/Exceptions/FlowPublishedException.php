<?php

declare(strict_types=1);

namespace App\Domain\Flows\Exceptions;

use DomainException;

/**
 * Operación denegada sobre un flujo `published` (409): editar/eliminar un
 * flujo publicado sin pasarlo antes a `draft` rompería la ejecución en curso.
 * El `code` `FLOW_PUBLISHED` permite al frontend distinguir el error.
 */
final class FlowPublishedException extends DomainException
{
    public const ERROR_CODE = 'FLOW_PUBLISHED';

    public const HTTP_STATUS = 409;

    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
