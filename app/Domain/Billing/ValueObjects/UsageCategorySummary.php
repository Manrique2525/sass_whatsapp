<?php

declare(strict_types=1);

namespace App\Domain\Billing\ValueObjects;

/**
 * Per-category usage summary: consumed, limit, and remaining.
 *
 * Immutable value object — no Eloquent dependency.
 * null limit/remaining = unlimited.
 */
final readonly class UsageCategorySummary
{
    public function __construct(
        public int $used,
        public ?int $limit,
        public ?int $remaining,
    ) {}
}
