<?php

declare(strict_types=1);

namespace App\Domain\AI\Exceptions;

use App\Domain\AI\Enums\AIErrorCode;

/**
 * The tenant plan does not include AI execution.
 */
final class AIFeatureNotIncludedException extends AIException
{
    public function __construct()
    {
        parent::__construct(
            'La IA no está incluida en el plan actual.',
            AIErrorCode::FeatureNotIncluded,
            403,
        );
    }
}
