<?php

declare(strict_types=1);

namespace App\Domain\AI\Exceptions;

use App\Domain\AI\Enums\EmbeddingErrorCode;

/**
 * Rate limit del proveedor de embeddings (HTTP 429).
 * Retryable.
 */
final class EmbeddingRateLimitException extends EmbeddingException
{
    public function __construct(string $message = 'Embedding rate limit exceeded')
    {
        parent::__construct($message, EmbeddingErrorCode::RateLimit, 429);
    }
}
