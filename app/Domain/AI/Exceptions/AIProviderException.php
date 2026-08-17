<?php

declare(strict_types=1);

namespace App\Domain\AI\Exceptions;

use App\Domain\AI\Enums\AIErrorCode;

/**
 * Error genérico del proveedor de IA (5xx, respuesta malformada, etc.).
 * Clasificación de retry depende del código HTTP subyacente.
 */
final class AIProviderException extends AIException
{
    private bool $retryable;

    public function __construct(string $message = 'AI provider error', bool $retryable = false, int $status = 502)
    {
        parent::__construct($message, AIErrorCode::ProviderError, $status);
        $this->retryable = $retryable;
    }

    public function retryable(): bool
    {
        return $this->retryable;
    }
}
