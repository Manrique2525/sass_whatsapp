<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Billing\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safe public representation of a Subscription (FASE 23 U3).
 *
 * No tenant_id exposed — the authenticated tenant is the implicit scope.
 *
 * @mixin Subscription
 */
final class SubscriptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'plan' => new PlanResource($this->whenLoaded('plan')),
            'status' => $this->status,
            'quantity' => $this->quantity,
            'current_period_start' => $this->current_period_start,
            'current_period_end' => $this->current_period_end,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
