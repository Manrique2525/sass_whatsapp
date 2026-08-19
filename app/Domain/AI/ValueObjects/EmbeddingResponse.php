<?php

declare(strict_types=1);

namespace App\Domain\AI\ValueObjects;

/**
 * Resultado de una llamada al proveedor de embeddings (FASE 17 U3.1).
 *
 * VO inmutable que encapsula la respuesta del proveedor incluyendo
 * vectores y métricas de uso de tokens. Diseñado para telemetría
 * sin acoplar al formato específico de ningún proveedor.
 *
 * El orden de embeddings[] coincide exactamente con el orden del input.
 */
final readonly class EmbeddingResponse
{
    /**
     * @param  list<list<float>>  $embeddings  Vectores en mismo orden que input.
     * @param  string  $provider  Nombre del proveedor (e.g. 'openai').
     * @param  string  $model  Modelo utilizado (e.g. 'text-embedding-3-small').
     * @param  int  $totalInputTokens  Total de tokens consumidos en el input.
     */
    public function __construct(
        public array $embeddings,
        public string $provider,
        public string $model,
        public int $totalInputTokens,
    ) {}
}
