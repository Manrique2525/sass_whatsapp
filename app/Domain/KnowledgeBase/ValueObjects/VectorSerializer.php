<?php

declare(strict_types=1);

namespace App\Domain\KnowledgeBase\ValueObjects;

use App\Domain\AI\Exceptions\EmbeddingDimensionMismatchException;
use App\Domain\AI\Exceptions\EmbeddingProviderException;

/**
 * Serialización y validación de vectores para pgvector (FASE 17 U3.2).
 *
 * Defense-in-depth: valida finitud y dimensionalidad antes de serializar.
 * El provider U3.1 ya valida, pero esta capa protege contra datos corruptos
 * o bypass accidental.
 *
 * Formato de salida: pgvector text — [0.1,0.2,...,0.5]
 */
final class VectorSerializer
{
    private const EXPECTED_DIMENSIONS = 1536;

    /**
     * Serializa un vector float a formato pgvector text con validación estricta.
     *
     * @param  list<mixed>  $vector
     */
    public static function serialize(array $vector): string
    {
        self::validate($vector);

        $serialized = array_map(fn (mixed $v): string => (string) (float) $v, $vector);

        return '['.implode(',', $serialized).']';
    }

    /**
     * Valida un vector sin serializar.
     *
     * @param  list<mixed>  $vector
     */
    public static function validate(array $vector, int $expectedDimensions = self::EXPECTED_DIMENSIONS): void
    {
        $count = count($vector);

        if ($count !== $expectedDimensions) {
            throw new EmbeddingDimensionMismatchException($expectedDimensions, $count);
        }

        foreach ($vector as $index => $value) {
            if (! is_numeric($value) || ! is_finite((float) $value)) {
                throw new EmbeddingProviderException(
                    "Embedding vector contains non-numeric or non-finite value at index {$index}.",
                );
            }
        }
    }

    /**
     * Retorna las dimensiones esperadas del contrato.
     */
    public static function expectedDimensions(): int
    {
        return self::EXPECTED_DIMENSIONS;
    }
}
