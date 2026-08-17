<?php

declare(strict_types=1);

namespace App\Domain\AI\Contracts;

use App\Domain\AI\Exceptions\AIAuthFailedException;
use App\Domain\AI\Exceptions\AIInvalidRequestException;
use App\Domain\AI\Exceptions\AIProviderException;
use App\Domain\AI\Exceptions\AIRateLimitException;
use App\Domain\AI\ValueObjects\AIRequest;
use App\Domain\AI\ValueObjects\AIResponse;

/**
 * Contrato del proveedor de IA (FASE 16).
 *
 * El dominio depende de esta interfaz, nunca de un proveedor concreto.
 * El provider es stateless respecto al tenant: recibe datos ya preparados.
 */
interface AIProviderInterface
{
    /**
     * Genera una respuesta de IA a partir de un prompt.
     *
     * @throws AIAuthFailedException
     * @throws AIRateLimitException
     * @throws AIInvalidRequestException
     * @throws AIProviderException
     */
    public function generateResponse(AIRequest $request): AIResponse;
}
