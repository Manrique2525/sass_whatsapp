<?php

declare(strict_types=1);

namespace App\Domain\Flows\Exceptions;

use DomainException;

/**
 * Publicar un flujo que ya está `published` (409). El `code`
 * `FLOW_ALREADY_PUBLISHED` permite al frontend distinguir el error del resto
 * de errores 409 del módulo.
 */
final class FlowAlreadyPublishedException extends DomainException
{
    public const ERROR_CODE = 'FLOW_ALREADY_PUBLISHED';

    public const HTTP_STATUS = 409;

    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
