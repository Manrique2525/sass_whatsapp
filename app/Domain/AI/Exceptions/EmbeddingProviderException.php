<?php

declare(strict_types=1);

namespace App\Domain\AI\Exceptions;

use App\Domain\AI\Enums\EmbeddingErrorCode;

/**
 * Error genérico del proveedor de embeddings (5xx, respuesta malformada, etc.).
 * Clasificación de retry depende del código HTTP subyacente.
 */
final class EmbeddingProviderException extends EmbeddingException
{
    private bool $retryable;

    public function __construct(string $message = 'Embedding provider error', bool $retryable = false, int $status = 502)
    {
        parent::__construct($message, EmbeddingErrorCode::ProviderError, $status);
        $this->retryable = $retryable;
    }

    public function retryable(): bool
    {
        return $this->retryable;
    }
}
