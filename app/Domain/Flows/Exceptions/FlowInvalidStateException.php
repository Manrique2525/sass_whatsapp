<?php

declare(strict_types=1);

namespace App\Domain\Flows\Exceptions;

use DomainException;

/**
 * Transición de estado de un flujo no permitida (409 FLOW_INVALID_STATE).
 * Ejemplo: deactivar un flujo que no está publicado, cancelar una ejecución
 * que ya terminó. El mensaje indica la transición intentada.
 */
final class FlowInvalidStateException extends DomainException
{
    public const ERROR_CODE = 'FLOW_INVALID_STATE';

    public const HTTP_STATUS = 409;
}
