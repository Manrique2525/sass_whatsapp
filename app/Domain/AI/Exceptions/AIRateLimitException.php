<?php

declare(strict_types=1);

namespace App\Domain\AI\Exceptions;

use App\Domain\AI\Enums\AIErrorCode;

/**
 * Rate limit del proveedor de IA (HTTP 429).
 * Retryable.
 */
final class AIRateLimitException extends AIException
{
    public function __construct(string $message = 'AI rate limit exceeded')
    {
        parent::__construct($message, AIErrorCode::RateLimit, 429);
    }
}
