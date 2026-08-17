<?php

declare(strict_types=1);

namespace App\Domain\AI\Exceptions;

use App\Domain\AI\Enums\AIErrorCode;

/**
 * Autenticación fallida con el proveedor de IA (API key inválida o ausente).
 * HTTP 401. No retryable.
 */
final class AIAuthFailedException extends AIException
{
    public function __construct(string $message = 'AI authentication failed')
    {
        parent::__construct($message, AIErrorCode::AuthFailed, 401);
    }
}
