<?php

declare(strict_types=1);

namespace App\Domain\AI\Exceptions;

use App\Domain\AI\Enums\EmbeddingErrorCode;

/**
 * El proveedor devolvió vectores con dimensionalidad incorrecta (FASE 17 U3.1).
 * Fail closed. No retryable.
 *
 * El mensaje contiene únicamente dimensiones esperada y actual.
 * Nunca incluye el vector completo ni datos del input.
 */
final class EmbeddingDimensionMismatchException extends EmbeddingException
{
    public function __construct(int $expected, int $actual)
    {
        parent::__construct(
            "Expected embedding dimension {$expected}, got {$actual}.",
            EmbeddingErrorCode::DimensionMismatch,
            422,
        );
    }
}
