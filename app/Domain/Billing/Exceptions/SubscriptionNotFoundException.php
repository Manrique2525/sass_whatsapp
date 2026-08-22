<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use RuntimeException;

/**
 * No active subscription found for the tenant when usage recording is attempted.
 *
 * Fail-closed: usage recording is refused rather than silently dropped.
 */
final class SubscriptionNotFoundException extends RuntimeException {}
