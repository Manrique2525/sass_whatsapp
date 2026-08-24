<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Billing\ValueObjects\UsageSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safe public representation of usage summary (FASE 23 U3).
 *
 * No tenant_id, no raw subscription internals.
 * Categories include used/limit/remaining per category.
 */
final class UsageSummaryResource extends JsonResource
{
    public function __construct(UsageSummary $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var UsageSummary $data */
        $data = $this->resource;

        $categories = [];

        foreach ($data->categories as $key => $category) {
            $categories[$key] = [
                'used' => $category->used,
                'limit' => $category->limit,
                'remaining' => $category->remaining,
            ];
        }

        return [
            'subscription_id' => $data->subscriptionId,
            'period_start' => $data->periodStart,
            'period_end' => $data->periodEnd,
            'categories' => $categories,
        ];
    }
}
