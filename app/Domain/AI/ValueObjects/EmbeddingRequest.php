<?php

declare(strict_types=1);

namespace App\Domain\AI\ValueObjects;

/**
 * Solicitud de generación de embeddings vectoriales (FASE 17 U3.1).
 *
 * VO inmutable que encapsula un batch de textos para convertir en vectores.
 * El dominio construye este objeto; el provider lo consume.
 */
final readonly class EmbeddingRequest
{
    /**
     * @param  list<mixed>  $input  Textos a embebir. Batch de 1..N elementos. Se valida que cada elemento sea string no vacío.
     * @param  string  $model  Modelo de embedding (override del default del provider).
     */
    public function __construct(
        public array $input,
        public string $model = '',
    ) {
        if ($input === []) {
            throw new \InvalidArgumentException('EmbeddingRequest input must not be empty.');
        }

        foreach ($input as $index => $text) {
            if (! is_string($text)) {
                throw new \InvalidArgumentException("EmbeddingRequest input[{$index}] must be a string.");
            }

            if (trim($text) === '') {
                throw new \InvalidArgumentException("EmbeddingRequest input[{$index}] must not be empty after trim.");
            }
        }
    }
}
