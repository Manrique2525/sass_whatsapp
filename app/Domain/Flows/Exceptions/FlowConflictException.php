<?php

declare(strict_types=1);

namespace App\Domain\Flows\Exceptions;

use DomainException;

/**
 * Conflicto de escritura concurrente (409) detectado por el lock optimista
 * (`base_updated_at`): el flujo fue modificado por otro usuario después de que
 * este cliente lo cargó. El `code` `FLOW_CONFLICT` permite al frontend mostrar
 * el modal de recargar/sobrescribir sin perder los cambios locales.
 */
final class FlowConflictException extends DomainException
{
    public const ERROR_CODE = 'FLOW_CONFLICT';

    public const HTTP_STATUS = 409;

    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
