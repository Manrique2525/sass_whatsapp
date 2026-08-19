<?php

declare(strict_types=1);

namespace App\Domain\AI\Contracts;

use App\Domain\AI\Exceptions\EmbeddingAuthFailedException;
use App\Domain\AI\Exceptions\EmbeddingDimensionMismatchException;
use App\Domain\AI\Exceptions\EmbeddingProviderException;
use App\Domain\AI\Exceptions\EmbeddingRateLimitException;
use App\Domain\AI\ValueObjects\EmbeddingRequest;
use App\Domain\AI\ValueObjects\EmbeddingResponse;

/**
 * Contrato del proveedor de embeddings vectoriales (FASE 17 U3.1).
 *
 * El dominio depende de esta interfaz, nunca de un proveedor concreto.
 * El provider es stateless respecto al tenant: recibe datos ya preparados.
 *
 * Separada de AIProviderInterface porque generación de texto y embeddings
 * son contratos fundamentalmente distintos (SRP + ISP).
 */
interface EmbeddingProviderInterface
{
    /**
     * Genera embeddings vectoriales a partir de textos de entrada.
     *
     * El orden de los embeddings en la respuesta debe coincidir exactamente
     * con el orden del input.
     *
     * @throws EmbeddingAuthFailedException
     * @throws EmbeddingRateLimitException
     * @throws EmbeddingDimensionMismatchException
     * @throws EmbeddingProviderException
     */
    public function embed(EmbeddingRequest $request): EmbeddingResponse;
}
