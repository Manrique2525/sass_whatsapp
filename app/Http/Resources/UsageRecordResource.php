<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Billing\Models\UsageRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safe public representation of a UsageRecord (FASE 23 U3).
 *
 * No tenant_id exposed. Metadata passes through U2 whitelist
 * (only safe technical IDs).
 *
 * @mixin UsageRecord
 */
final class UsageRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category,
            'quantity' => $this->quantity,
            'description' => $this->description,
            'metadata' => $this->metadata,
            'recorded_at' => $this->recorded_at,
            'created_at' => $this->created_at,
        ];
    }
}
