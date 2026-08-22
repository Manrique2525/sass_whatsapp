<?php

declare(strict_types=1);

namespace App\Domain\Billing\ValueObjects;

/**
 * Full usage summary across all categories for the current billing period.
 *
 * Immutable value object — no Eloquent dependency.
 */
final readonly class UsageSummary
{
    /**
     * @param  array<string, UsageCategorySummary>  $categories
     */
    public function __construct(
        public string $subscriptionId,
        public string $periodStart,
        public string $periodEnd,
        public array $categories,
    ) {}
}
