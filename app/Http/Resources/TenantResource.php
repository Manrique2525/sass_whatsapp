<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Tenants\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Tenant
 */
final class TenantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status,
            'timezone' => $this->timezone,
            'locale' => $this->locale,
            'role' => $this->whenPivotLoaded('tenant_users', fn (): ?string => $this->pivot?->getAttribute('role')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
