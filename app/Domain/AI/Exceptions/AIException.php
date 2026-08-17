<?php

declare(strict_types=1);

namespace App\Domain\AI\Exceptions;

use App\Domain\AI\Enums\AIErrorCode;
use RuntimeException;

/**
 * Excepción base del dominio AI (FASE 16).
 *
 * Todas las excepciones AI heredan de esta clase. Permite al motor
 * de flujos capturar cualquier error AI con un solo catch.
 */
abstract class AIException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly AIErrorCode $errorCode,
        int $status = 500,
    ) {
        parent::__construct($message, $status);
    }

    public function errorCode(): AIErrorCode
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return (int) $this->getCode();
    }
}
