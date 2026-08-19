<?php

declare(strict_types=1);

namespace App\Domain\AI\Exceptions;

use App\Domain\AI\Enums\EmbeddingErrorCode;
use RuntimeException;

/**
 * Excepción base del dominio de embeddings (FASE 17 U3.1).
 *
 * Todas las excepciones de embedding heredan de esta clase.
 */
abstract class EmbeddingException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly EmbeddingErrorCode $errorCode,
        int $status = 500,
    ) {
        parent::__construct($message, $status);
    }

    public function errorCode(): EmbeddingErrorCode
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return (int) $this->getCode();
    }
}
