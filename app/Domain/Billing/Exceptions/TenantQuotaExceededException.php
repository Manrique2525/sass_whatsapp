<?php

declare(strict_types=1);

namespace App\Domain\Billing\Exceptions;

use DomainException;

/**
 * Tenant has exhausted their usage quota for a specific category.
 *
 * HTTP 429, code TENANT_QUOTA_EXCEEDED.
 * Safe fields only: category, limit, used.
 */
final class TenantQuotaExceededException extends DomainException
{
    public function __construct(
        string $category,
        ?int $limit,
        int $used,
    ) {
        $limitDisplay = $limit === null ? 'unlimited' : (string) $limit;

        parent::__construct(
            "Tenant quota exceeded for category [{$category}]: used {$used}, limit {$limitDisplay}.",
            429,
        );

        $this->category = $category;
        $this->limit = $limit;
        $this->used = $used;
    }

    public static function forQuota(string $category, ?int $limit, int $used): self
    {
        return new self($category, $limit, $used);
    }

    public readonly string $category;

    public readonly ?int $limit;

    public readonly int $used;
}
