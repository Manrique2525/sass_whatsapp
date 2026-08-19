<?php

declare(strict_types=1);

namespace App\Domain\AI\Exceptions;

use App\Domain\AI\Enums\EmbeddingErrorCode;

/**
 * Autenticación fallida con el proveedor de embeddings (API key inválida o ausente).
 * HTTP 401. No retryable.
 */
final class EmbeddingAuthFailedException extends EmbeddingException
{
    public function __construct(string $message = 'Embedding authentication failed')
    {
        parent::__construct($message, EmbeddingErrorCode::AuthFailed, 401);
    }
}
