<?php

declare(strict_types=1);

namespace App\Domain\Flows\Exceptions;

use DomainException;

/**
 * El flujo no pasa la validación del `FlowValidator` y no puede publicarse
 * (422). `errors()` expone la lista de problemas (mensajes de dominio, nunca
 * SQL); el `code` `FLOW_INVALID` permite al frontend distinguirlo.
 */
final class FlowInvalidException extends DomainException
{
    public const ERROR_CODE = 'FLOW_INVALID';

    public const HTTP_STATUS = 422;

    /**
     * @var list<string>
     */
    private array $errors;

    /**
     * @param  list<string>  $errors
     */
    public function __construct(string $message, array $errors = [])
    {
        parent::__construct($message);

        $this->errors = $errors;
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
