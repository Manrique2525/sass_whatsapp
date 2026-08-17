<?php

declare(strict_types=1);

namespace App\Domain\AI\Enums;

/**
 * Códigos de error del dominio AI (FASE 16).
 *
 * Cada código corresponde a una clase de excepción específica.
 * El error code es el contrato estable que el frontend y el motor
 * de flujos pueden consumir sin conocer la implementación del provider.
 */
enum AIErrorCode: string
{
    case AuthFailed = 'AI_AUTH_FAILED';
    case RateLimit = 'AI_RATE_LIMIT';
    case InvalidRequest = 'AI_INVALID_REQUEST';
    case ProviderError = 'AI_PROVIDER_ERROR';
    case Timeout = 'AI_TIMEOUT';
    case ResponseInvalid = 'AI_RESPONSE_INVALID';
}
