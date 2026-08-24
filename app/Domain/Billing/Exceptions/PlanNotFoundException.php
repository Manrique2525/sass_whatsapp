<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use DomainException;

/**
 * Plan not found or not accessible (FASE 23 U3).
 *
 * Mapped to 404 by controllers; generic message hides existence.
 */
final class PlanNotFoundException extends DomainException
{
    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? 'Plan no encontrado.');
    }
}
