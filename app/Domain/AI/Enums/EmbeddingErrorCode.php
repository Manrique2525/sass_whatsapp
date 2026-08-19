<?php

declare(strict_types=1);

namespace App\Domain\AI\Enums;

/**
 * Códigos de error del dominio de embeddings (FASE 17 U3.1).
 *
 * Cada código corresponde a una clase de excepción específica.
 * El error code es el contrato estable que la telemetría y
 * el materialization service pueden consumir sin conocer
 * la implementación del provider.
 */
enum EmbeddingErrorCode: string
{
    case AuthFailed = 'EMBEDDING_AUTH_FAILED';
    case RateLimit = 'EMBEDDING_RATE_LIMIT';
    case InvalidRequest = 'EMBEDDING_INVALID_REQUEST';
    case InvalidResponse = 'EMBEDDING_INVALID_RESPONSE';
    case DimensionMismatch = 'EMBEDDING_DIMENSION_MISMATCH';
    case ProviderError = 'EMBEDDING_PROVIDER_ERROR';
    case Timeout = 'EMBEDDING_TIMEOUT';
}
