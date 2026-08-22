<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use InvalidArgumentException;

/**
 * Usage quantity must be a positive integer (> 0).
 */
final class InvalidUsageQuantityException extends InvalidArgumentException {}
