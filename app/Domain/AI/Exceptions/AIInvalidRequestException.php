<?php

declare(strict_types=1);

namespace App\Domain\AI\Exceptions;

use App\Domain\AI\Enums\AIErrorCode;

/**
 * Solicitud inválida al proveedor de IA (HTTP 400).
 * No retryable.
 */
final class AIInvalidRequestException extends AIException
{
    public function __construct(string $message = 'AI invalid request')
    {
        parent::__construct($message, AIErrorCode::InvalidRequest, 400);
    }
}
