<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use DomainException;

/**
 * Subscription is not in active state (FASE 23 U3).
 *
 * Mapped to 409 by controllers.
 */
final class SubscriptionNotActiveException extends DomainException
{
    public const string ERROR_CODE = 'SUBSCRIPTION_NOT_ACTIVE';

    public const int HTTP_STATUS = 409;

    public function __construct()
    {
        parent::__construct('No hay una suscripción activa para este tenant.');
    }
}
