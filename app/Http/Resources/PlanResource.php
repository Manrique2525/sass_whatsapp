<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Billing\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safe public representation of a Plan (FASE 23 U3).
 *
 * No internal IDs beyond the public UUID. No tenant_id (plans are global).
 *
 * @mixin Plan
 */
final class PlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'price_monthly' => $this->price_monthly,
            'price_yearly' => $this->price_yearly,
            'limits' => $this->limits,
            'features' => $this->features,
            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
