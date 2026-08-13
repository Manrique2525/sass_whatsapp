<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Business\Models\BusinessProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BusinessProfile
 */
final class BusinessProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'address' => $this->address,
            'website' => $this->website,
            'email' => $this->email,
            'phone' => $this->phone,
            'working_hours' => $this->working_hours,
            'updated_at' => $this->updated_at,
        ];
    }
}
