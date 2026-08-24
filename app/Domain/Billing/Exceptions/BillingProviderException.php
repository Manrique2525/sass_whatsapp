<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use RuntimeException;

/**
 * Error del proveedor de facturación (FASE 24 U1).
 *
 * Las implementaciones (StripeProvider) traducen excepciones del SDK
 * a esta clase. Nunca se exponen excepciones del SDK al usuario.
 * El flag `retryable` indica si el error es transitorio (5xx/timeout).
 */
final class BillingProviderException extends RuntimeException
{
    private bool $retryable;

    public function __construct(string $message = 'Billing provider error', bool $retryable = false, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->retryable = $retryable;
    }

    public function retryable(): bool
    {
        return $this->retryable;
    }
}
